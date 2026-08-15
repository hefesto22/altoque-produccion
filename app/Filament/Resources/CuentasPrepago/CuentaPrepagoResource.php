<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPrepago;

use App\Filament\Resources\CuentasPrepago\Pages\ListCuentasPrepago;
use App\Filament\Schemas\Components\MontoField;
use App\Filament\Schemas\Components\TelefonoHondurasField;
use App\Models\Cliente;
use App\Models\CuentaPrepago;
use App\Services\Cuentas\CuentaPrepagoService;
use App\Support\Acceso;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Cuentas prepago: empresas y personas que dejan dinero por adelantado y
 * consumen contra ese saldo.
 *
 * El depósito NO es una venta — no consume CAI ni lleva ISV. La factura sale
 * en cada consumo, desde el POS. Acá solo entra plata y se consulta.
 */
class CuentaPrepagoResource extends Resource
{
    protected static ?string $model = CuentaPrepago::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wallet';

    protected static ?string $modelLabel = 'Cuenta prepago';

    protected static ?string $pluralModelLabel = 'Cuentas prepago';

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return 'Caja';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Titular de la cuenta')
                ->icon('heroicon-o-identification')
                ->schema([
                    // El RTN NO se escribe acá: vive en Clientes y se lee de
                    // ahí. Dos copias del mismo dato terminan discrepando.
                    Select::make('cliente_id')
                        ->label('Cliente asociado')
                        ->relationship('cliente', 'nombre')
                        ->searchable(['nombre', 'rtn'])
                        ->preload()
                        ->getOptionLabelFromRecordUsing(fn (Cliente $record): string => $record->rtn !== null
                            ? $record->nombre.' · RTN '.$record->rtn
                            : $record->nombre)
                        ->createOptionForm([
                            TextInput::make('nombre')->label('Nombre / Razón social')->required()->maxLength(255),
                            TextInput::make('rtn')->label('RTN')->required()->maxLength(14)->unique('clientes', 'rtn'),
                        ])
                        ->createOptionUsing(fn (array $data): int => Cliente::registrar($data['rtn'], $data['nombre'])->id)
                        ->helperText('Con el RTN de este cliente se factura cada consumo. Si no está en la lista, lo creás desde el botón +.')
                        ->live()
                        // Comodidad: si el nombre de la cartera está vacío, se
                        // copia el del cliente. Nunca pisa lo que ya escribió.
                        ->afterStateUpdated(function (?string $state, Get $get, callable $set): void {
                            if ($state === null || trim((string) $get('nombre')) !== '') {
                                return;
                            }

                            $cliente = Cliente::find($state);

                            if ($cliente !== null) {
                                $set('nombre', $cliente->nombre);
                            }
                        })
                        ->columnSpanFull(),
                    TextInput::make('nombre')
                        ->label('Nombre de la cuenta')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Cómo se llama la cartera. Suele ser el mismo del cliente.'),
                    TelefonoHondurasField::make('telefono', 'Teléfono')
                        ->helperText('Para mandarle el link de su estado de cuenta por WhatsApp.'),
                ])->columns(2),

            Section::make('Crédito')
                ->icon('heroicon-o-scale')
                ->description('Por defecto solo se puede consumir lo que hay depositado. El crédito es para empresas de confianza: deja que el saldo quede en rojo hasta el tope.')
                ->schema([
                    Toggle::make('permite_credito')
                        ->label('Permitir consumir sin saldo')
                        ->live()
                        ->inline(false),
                    MontoField::make('limite_credito', 'Tope de crédito')
                        ->visible(fn (Get $get): bool => (bool) $get('permite_credito'))
                        ->helperText('Hasta cuánto puede quedar en rojo la cuenta.'),
                ])->columns(2),

            Section::make('Estado')
                ->schema([
                    Toggle::make('activa')
                        ->label('Cuenta activa')
                        ->default(true)
                        ->inline(false)
                        ->helperText('Una cuenta inactiva no se puede usar para cobrar, pero conserva su saldo y su historial.'),
                    Textarea::make('notas')->label('Notas internas')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('nombre')
            ->columns([
                TextColumn::make('nombre')->label('Cuenta')->searchable()->sortable()
                    ->description(fn (CuentaPrepago $record): string => $record->cliente !== null
                        ? $record->cliente->nombre.' · RTN '.$record->cliente->rtn
                        : 'Sin cliente asociado — se factura a Consumidor Final')
                    ->weight('bold'),
                TextColumn::make('saldo')->label('Saldo')->money('HNL')->sortable()
                    // Rojo = la cuenta está usando su crédito.
                    ->color(fn (CuentaPrepago $record): string => (float) $record->saldo < 0 ? 'danger' : 'success')
                    ->weight('bold'),
                TextColumn::make('limite_credito')->label('Crédito')->money('HNL')
                    ->placeholder('—')
                    ->getStateUsing(fn (CuentaPrepago $record): ?float => $record->permite_credito ? (float) $record->limite_credito : null)
                    ->toggleable(),
                TextColumn::make('telefono')->label('Teléfono')->toggleable()->placeholder('—'),
                IconColumn::make('activa')->label('Activa')->boolean(),
                TextColumn::make('updated_at')->label('Último movimiento')->since()->sortable()->toggleable(),
            ])
            ->recordActions([
                // Entra plata. NO es una venta: no consume CAI ni lleva ISV.
                Action::make('depositar')
                    ->label('Depósito')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->modalHeading('Registrar depósito')
                    ->modalDescription(fn (CuentaPrepago $record): string => $record->nombre
                        .' · Saldo actual L. '.number_format((float) $record->saldo, 2))
                    ->schema([
                        MontoField::make('monto', 'Monto del depósito')
                            ->helperText('El depósito no genera factura: la factura sale en cada consumo.'),
                        ToggleButtons::make('forma_pago')->label('Cómo entró')
                            ->options(['efectivo' => 'Efectivo', 'transferencia' => 'Transferencia', 'cheque' => 'Cheque'])
                            ->icons([
                                'efectivo'      => 'heroicon-o-banknotes',
                                'transferencia' => 'heroicon-o-building-library',
                                'cheque'        => 'heroicon-o-document-text',
                            ])
                            ->inline()
                            ->default('transferencia')
                            ->required()
                            ->live(),
                        TextInput::make('banco')->label('Banco')
                            ->visible(fn (Get $get): bool => in_array($get('forma_pago'), ['transferencia', 'cheque'], true))
                            ->maxLength(60),
                        TextInput::make('referencia')->label('N.º de cheque o referencia')
                            ->visible(fn (Get $get): bool => in_array($get('forma_pago'), ['transferencia', 'cheque'], true))
                            ->maxLength(60),
                        TextInput::make('notas')->label('Nota (opcional)')->maxLength(255),
                    ])
                    ->visible(fn (): bool => Acceso::puede('Update:CuentaPrepago'))
                    ->action(function (CuentaPrepago $record, array $data): void {
                        abort_unless(Acceso::puede('Update:CuentaPrepago'), 403);

                        app(CuentaPrepagoService::class)->depositar(
                            $record,
                            (float) $data['monto'],
                            $data['forma_pago'],
                            $data['banco'] ?? null,
                            $data['referencia'] ?? null,
                            $data['notas'] ?? null,
                            (int) Auth::id(),
                        );

                        Notification::make()
                            ->title('Depósito registrado')
                            ->body('Saldo nuevo: L. '.number_format((float) $record->fresh()->saldo, 2))
                            ->success()
                            ->send();
                    }),

                // Corrección manual. Lleva motivo obligatorio: plata que se
                // mueve sin explicación es un agujero.
                Action::make('ajustar')
                    ->label('Ajuste')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->modalHeading('Ajustar el saldo a mano')
                    ->modalDescription(fn (CuentaPrepago $record): string => $record->nombre
                        .' · Saldo actual L. '.number_format((float) $record->saldo, 2)
                        .'. Usalo solo para corregir un error; los depósitos van por su propio botón.')
                    ->schema([
                        TextInput::make('monto')->label('Monto del ajuste')
                            ->required()->numeric()->step(0.01)->prefix('L.')
                            ->helperText('En positivo suma al saldo; en negativo lo resta (ej: -150.00).'),
                        TextInput::make('motivo')->label('Motivo')->required()->maxLength(255)
                            ->placeholder('Se cobró dos veces el almuerzo del martes'),
                    ])
                    ->visible(fn (): bool => Acceso::puede('Update:CuentaPrepago'))
                    ->action(function (CuentaPrepago $record, array $data): void {
                        abort_unless(Acceso::puede('Update:CuentaPrepago'), 403);

                        $monto = round((float) $data['monto'], 2);

                        if (abs($monto) < 0.01) {
                            Notification::make()->title('Un ajuste de cero no cambia nada')->warning()->send();

                            return;
                        }

                        app(CuentaPrepagoService::class)->ajustar($record, $monto, $data['motivo'], (int) Auth::id());

                        Notification::make()
                            ->title('Saldo ajustado')
                            ->body('Saldo nuevo: L. '.number_format((float) $record->fresh()->saldo, 2))
                            ->success()
                            ->send();
                    }),

                Action::make('movimientos')
                    ->label('Movimientos')
                    ->icon('heroicon-o-list-bullet')
                    ->color('gray')
                    ->modalHeading(fn (CuentaPrepago $record): string => 'Movimientos — '.$record->nombre)
                    ->modalContent(fn (CuentaPrepago $record) => view('filament.cuentas.movimientos', [
                        'cuenta'      => $record,
                        'movimientos' => $record->movimientos()->with(['venta.factura:id,venta_id,numero', 'registradoPor:id,name'])->limit(100)->get(),
                    ]))
                    ->modalSubmitAction(false),

                Action::make('estado')
                    ->label('Ver estado')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->url(fn (CuentaPrepago $record): string => $record->urlEstado(), shouldOpenInNewTab: true),

                Action::make('whatsapp')
                    ->label('WhatsApp')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('success')
                    ->url(fn (CuentaPrepago $record): string => $record->urlWhatsApp(), shouldOpenInNewTab: true),

                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCuentasPrepago::route('/'),
        ];
    }
}
