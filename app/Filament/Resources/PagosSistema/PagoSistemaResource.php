<?php

declare(strict_types=1);

namespace App\Filament\Resources\PagosSistema;

use App\Domain\Exceptions\RestauranteException;
use App\Filament\Resources\PagosSistema\Pages\ListPagosSistema;
use App\Filament\Schemas\Components\MontoField;
use App\Models\PagoSistema;
use App\Services\Pagos\PagoSistemaService;
use App\Support\Acceso;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Pagos del sistema: las cuotas mensuales del contrato con el desarrollador.
 *
 * NO es plata del negocio. No toca CAI, ni ISV, ni cortes de caja, ni los
 * libros fiscales — es un gasto del restaurante, no una venta. Los impuestos
 * de este contrato los declara el desarrollador aparte, así que los montos
 * de acá van limpios.
 *
 * El gerente entra a MIRAR (cuánto lleva pagado, cuánto falta, qué mes toca).
 * Marcar una cuota como pagada es del super_admin — ver PagoSistemaPolicy.
 */
class PagoSistemaResource extends Resource
{
    protected static ?string $model = PagoSistema::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $modelLabel = 'Pago del sistema';

    protected static ?string $pluralModelLabel = 'Pagos del sistema';

    protected static ?string $navigationLabel = 'Pagos';

    protected static ?int $navigationSort = 9;

    public static function getNavigationGroup(): ?string
    {
        return 'Administración';
    }

    /** Cuotas atrasadas en el ícono del menú: lo que hay que ver sin entrar. */
    public static function getNavigationBadge(): ?string
    {
        $atrasadas = PagoSistema::query()->atrasadas()->count();

        return $atrasadas > 0 ? (string) $atrasadas : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('periodo')
            ->paginated([12, 24, 50])
            ->defaultPaginationPageOption(24)
            ->columns([
                TextColumn::make('numero')
                    ->label('Cuota')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('periodo')
                    ->label('Mes')
                    ->formatStateUsing(fn (PagoSistema $record): string => $record->mesLabel())
                    ->description(fn (PagoSistema $record): string => $record->concepto)
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('monto')->label('Pactado')->money('HNL')->sortable(),
                TextColumn::make('monto_pagado')->label('Pagado')->money('HNL')->placeholder('—'),
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->getStateUsing(fn (PagoSistema $record): string => $record->estadoLabel())
                    ->color(fn (PagoSistema $record): string => $record->estadoColor()),
                TextColumn::make('pagada_at')->label('Se pagó')->dateTime('d/m/Y')->placeholder('—')->toggleable(),
                TextColumn::make('forma_pago')->label('Cómo')->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : ucfirst($state))
                    ->toggleable(),
                TextColumn::make('referencia')->label('Referencia')->placeholder('—')->toggleable(),
                TextColumn::make('marcadaPor.name')->label('Marcado por')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('notas')->label('Nota')->placeholder('—')->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options([
                        'pagada'    => 'Pagadas',
                        'pendiente' => 'Pendientes',
                        'atrasada'  => 'Atrasadas',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'pagada'    => $query->where('pagada', true),
                        'pendiente' => $query->where('pagada', false),
                        'atrasada'  => $query->where('pagada', false)->whereDate('periodo', '<', now()->startOfMonth()),
                        default     => $query,
                    }),
            ])
            ->recordActions([
                Action::make('marcarPagada')
                    ->label('Marcar pagada')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->modalHeading(fn (PagoSistema $record): string => 'Pago de '.$record->mesLabel())
                    ->modalDescription(fn (PagoSistema $record): string => 'Cuota '.$record->numero
                        .' · '.$record->concepto.' · Pactado L. '.number_format((float) $record->monto, 2))
                    ->modalSubmitActionLabel('Marcar como pagada')
                    ->fillForm(fn (PagoSistema $record): array => [
                        'monto'      => (float) $record->monto,
                        'forma_pago' => 'transferencia',
                        'banco'      => config('cobro.banco'),
                    ])
                    ->schema([
                        MontoField::make('monto', 'Monto recibido')
                            ->helperText('Viene con lo pactado. Cambialo solo si entró un monto distinto.'),
                        ToggleButtons::make('forma_pago')->label('Cómo llegó')
                            ->options(['efectivo' => 'Efectivo', 'transferencia' => 'Transferencia', 'cheque' => 'Cheque'])
                            ->icons([
                                'efectivo'      => 'heroicon-o-banknotes',
                                'transferencia' => 'heroicon-o-building-library',
                                'cheque'        => 'heroicon-o-document-text',
                            ])
                            ->inline()
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
                    ->visible(fn (PagoSistema $record): bool => ! $record->pagada && Acceso::puede('Update:PagoSistema'))
                    ->action(function (PagoSistema $record, array $data): void {
                        abort_unless(Acceso::puede('Update:PagoSistema'), 403);

                        try {
                            app(PagoSistemaService::class)->marcarPagada(
                                $record,
                                (float) $data['monto'],
                                $data['forma_pago'],
                                $data['banco'] ?? null,
                                $data['referencia'] ?? null,
                                $data['notas'] ?? null,
                                (int) Auth::id(),
                            );
                        } catch (RestauranteException $e) {
                            Notification::make()->title($e->getMessage())->warning()->send();

                            return;
                        }

                        Notification::make()
                            ->title($record->mesLabel().' quedó pagado')
                            ->body('Saldo del contrato: L. '.number_format(PagoSistema::resumen()['saldo'], 2))
                            ->success()
                            ->send();
                    }),

                Action::make('revertir')
                    ->label('Deshacer')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->modalHeading(fn (PagoSistema $record): string => 'Deshacer el pago de '.$record->mesLabel())
                    ->modalDescription('La cuota vuelve a quedar pendiente. Queda registrado quién lo deshizo y por qué.')
                    ->schema([
                        TextInput::make('motivo')->label('Motivo')->required()->maxLength(255)
                            ->placeholder('Se marcó el mes equivocado'),
                    ])
                    ->visible(fn (PagoSistema $record): bool => $record->pagada && Acceso::puede('Update:PagoSistema'))
                    ->action(function (PagoSistema $record, array $data): void {
                        abort_unless(Acceso::puede('Update:PagoSistema'), 403);

                        try {
                            app(PagoSistemaService::class)->revertir($record, $data['motivo'], (int) Auth::id());
                        } catch (RestauranteException $e) {
                            Notification::make()->title($e->getMessage())->warning()->send();

                            return;
                        }

                        Notification::make()->title('Pago deshecho')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPagosSistema::route('/'),
        ];
    }
}
