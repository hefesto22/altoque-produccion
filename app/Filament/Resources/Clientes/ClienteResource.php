<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes;

use App\Filament\Resources\Clientes\Pages\ManageClientes;
use App\Filament\Schemas\Components\RTNField;
use App\Models\Cliente;
use App\Models\Factura;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class ClienteResource extends Resource
{
    protected static ?string $model = Cliente::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static ?string $modelLabel = 'Cliente';

    protected static ?string $pluralModelLabel = 'Clientes';

    protected static ?int $navigationSort = 2;

    /** Máximo de facturas listadas en el historial (los totales sí cubren todo). */
    private const HISTORIAL_LIMITE = 200;

    public static function getNavigationGroup(): ?string
    {
        return 'Facturación';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            RTNField::make('rtn', true),
            TextInput::make('nombre')->label('Nombre / Razón social')->required()
                ->dehydrateStateUsing(fn (string $state): string => mb_strtoupper(trim($state))),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')->label('Nombre')->searchable()->sortable()->weight('bold'),
                TextColumn::make('rtn')->label('RTN')->searchable()->copyable(),
                TextColumn::make('created_at')->label('Registrado')->date('d/m/Y')->toggleable(),
            ])
            ->defaultSort('nombre')
            ->recordActions([
                // Historial de compras (pedido del restaurante): todas las
                // facturas emitidas a este RTN, con totales y desglose por
                // mes, para darle al cliente un registro de cuánto compró.
                Action::make('historial')
                    ->label('Historial')
                    ->icon('heroicon-o-clock')
                    ->color('info')
                    ->slideOver()
                    ->modalWidth(Width::TwoExtraLarge)
                    ->modalHeading(fn (Cliente $record): string => 'Historial de compras')
                    ->modalDescription(fn (Cliente $record): string => $record->nombre.' · RTN '.$record->rtn)
                    ->modalIcon('heroicon-o-clock')
                    ->modalContent(fn (Cliente $record) => view(
                        'filament.clientes.historial-compras',
                        self::datosHistorial($record),
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar'),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * Datos del historial: totales sobre TODO el historial (agregados en SQL,
     * las anuladas no suman) + las últimas facturas para el detalle.
     *
     * @return array<string, mixed>
     */
    private static function datosHistorial(Cliente $cliente): array
    {
        $stats = Factura::query()
            ->where('rtn_cliente', $cliente->rtn)
            ->where('anulada', false)
            ->toBase()
            ->selectRaw('COUNT(*) AS compras, COALESCE(SUM(total), 0) AS total_comprado, MAX(emitida_at) AS ultima_compra')
            ->first();

        $facturas = Factura::query()
            ->where('rtn_cliente', $cliente->rtn)
            ->orderByDesc('emitida_at')
            ->limit(self::HISTORIAL_LIMITE)
            ->get(['id', 'numero', 'forma_pago', 'total', 'anulada', 'emitida_at']);

        return [
            'compras' => (int) ($stats->compras ?? 0),
            'total'   => (float) ($stats->total_comprado ?? 0),
            'ultima'  => $stats !== null && $stats->ultima_compra !== null
                ? Carbon::parse((string) $stats->ultima_compra)
                : null,
            'facturas' => $facturas,
            'truncado' => $facturas->count() === self::HISTORIAL_LIMITE,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageClientes::route('/'),
        ];
    }
}
