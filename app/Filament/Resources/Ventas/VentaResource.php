<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas;

use App\Domain\Exceptions\RestauranteException;
use App\Filament\Pages\PuntoDeVenta;
use App\Filament\Resources\Ventas\Pages\ListVentas;
use App\Models\Venta;
use App\Services\Facturacion\FacturacionSarService;
use App\Services\Pos\VentaService;
use App\Support\Acceso;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class VentaResource extends Resource
{
    protected static ?string $model = Venta::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $modelLabel = 'Venta';

    protected static ?string $pluralModelLabel = 'Ventas';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Ventas';
    }

    /** Las ventas se registran desde el POS, no desde el CRUD. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /** Columnas explícitas + eager loading del cajero (sin N+1). */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->select(['id', 'tipo', 'forma_pago', 'banco', 'numero_recibo', 'rtn_cliente', 'gravado', 'exento', 'isv', 'total', 'cajero_id', 'corte_caja_id', 'pagada', 'vendida_at'])
            // comanda va sin restricción de columnas: es latestOfMany() y su
            // JOIN interno hace ambiguo un "venta_id" sin prefijo de tabla.
            ->with(['cajero:id,name', 'factura', 'comanda', 'corte:id,estado']);
    }

    public static function table(Table $table): Table
    {
        return $table
            // El listado se refresca solo: las ventas que registra la caja
            // aparecen sin recargar la página (pedido del restaurante).
            ->poll('10s')
            ->columns([
                TextColumn::make('vendida_at')->label('Fecha')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('tipo')->label('Tipo')->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => $state === 'factura' ? 'success' : 'gray'),
                TextColumn::make('numero_recibo')->label('Recibo')->placeholder('—')->searchable(),
                TextColumn::make('factura.numero')->label('Factura')->placeholder('—')->searchable(),
                TextColumn::make('forma_pago')->label('Pago')->badge()
                    ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state))
                    ->description(fn (Venta $record): ?string => $record->banco)
                    ->tooltip(fn (): ?string => Acceso::puede('CorregirPago') ? 'Clic para corregir (control interno)' : null)
                    // Clic en el badge = corregir el método (control interno, auditado).
                    // No altera la factura ni el desglose fiscal. Un solo método
                    // asume el total completo; "Mixto" reparte el total entre
                    // métodos (mismos tres montos + banco que el cobro del POS)
                    // y el servicio valida que la suma cuadre al centavo.
                    ->action(
                        Action::make('corregir_pago')
                            ->modalHeading('Corregir forma de pago (control interno)')
                            ->modalDescription(fn (Venta $record): string => 'Total: L. '.number_format((float) $record->total, 2).' — con un solo método, ese asume el total completo; con mixto, los montos deben sumar exactamente ese total. La factura NO se altera; solo el registro interno.'
                                .($record->corte?->estado === 'cerrado'
                                    ? ' ⚠️ El turno de esta venta YA CERRÓ: el desglose de ese corte no se recalcula.'
                                    : ''))
                            ->fillForm(function (Venta $record): array {
                                // Si ya es mixto, precarga los montos actuales para
                                // ajustar sobre lo que hay (no arrancar de cero).
                                $pagos = $record->forma_pago === 'mixto'
                                    ? $record->pagos()->get(['metodo', 'banco', 'monto'])
                                    : collect();

                                return [
                                    'forma_pago'          => $record->forma_pago,
                                    'banco'               => $record->banco ?? $pagos->whereNotNull('banco')->first()?->banco,
                                    'mixto_efectivo'      => $pagos->firstWhere('metodo', 'efectivo')?->monto,
                                    'mixto_tarjeta'       => $pagos->firstWhere('metodo', 'tarjeta')?->monto,
                                    'mixto_transferencia' => $pagos->firstWhere('metodo', 'transferencia')?->monto,
                                ];
                            })
                            ->schema([
                                Select::make('forma_pago')
                                    ->label('Forma de pago')
                                    ->options([
                                        'efectivo'      => 'Efectivo',
                                        'tarjeta'       => 'Tarjeta',
                                        'transferencia' => 'Transferencia',
                                        'mixto'         => 'Mixto (varios métodos)',
                                    ])
                                    ->required()
                                    ->live(),
                                // ── Montos del pago mixto (mismo esquema que el POS) ──
                                TextInput::make('mixto_efectivo')
                                    ->label('Efectivo')
                                    ->numeric()->minValue(0)->prefix('L.')
                                    ->live(debounce: 500)
                                    ->visible(fn (Get $get): bool => $get('forma_pago') === 'mixto'),
                                TextInput::make('mixto_tarjeta')
                                    ->label('Tarjeta')
                                    ->numeric()->minValue(0)->prefix('L.')
                                    ->live(debounce: 500)
                                    ->visible(fn (Get $get): bool => $get('forma_pago') === 'mixto'),
                                TextInput::make('mixto_transferencia')
                                    ->label('Transferencia')
                                    ->numeric()->minValue(0)->prefix('L.')
                                    ->live(debounce: 500)
                                    ->visible(fn (Get $get): bool => $get('forma_pago') === 'mixto'),
                                Placeholder::make('mixto_resumen')
                                    ->hiddenLabel()
                                    ->content(function (Get $get, Venta $record): string {
                                        $suma = round((float) $get('mixto_efectivo') + (float) $get('mixto_tarjeta') + (float) $get('mixto_transferencia'), 2);
                                        $total = round((float) $record->total, 2);
                                        $resta = round($total - $suma, 2);

                                        return match (true) {
                                            abs($resta) < 0.01 => '✅ Asignado L. '.number_format($suma, 2).' — cuadra con el total.',
                                            $resta > 0         => 'Asignado L. '.number_format($suma, 2).' — falta L. '.number_format($resta, 2),
                                            default            => '⚠️ Asignado L. '.number_format($suma, 2).' — se pasa por L. '.number_format(abs($resta), 2),
                                        };
                                    })
                                    ->visible(fn (Get $get): bool => $get('forma_pago') === 'mixto'),
                                Select::make('banco')
                                    ->label('Banco')
                                    ->helperText(fn (Get $get): ?string => $get('forma_pago') === 'mixto'
                                        ? 'Aplica a la parte con tarjeta o transferencia.'
                                        : null)
                                    ->options(array_combine(config('empresa.bancos', []), config('empresa.bancos', [])))
                                    ->required(fn (Get $get): bool => $get('forma_pago') === 'transferencia'
                                        || ($get('forma_pago') === 'mixto' && (float) $get('mixto_transferencia') > 0))
                                    ->visible(fn (Get $get): bool => in_array($get('forma_pago'), ['tarjeta', 'transferencia', 'mixto'], true)),
                            ])
                            ->visible(fn (Venta $record): bool => Acceso::puede('CorregirPago') && $record->pagada)
                            ->action(function (Venta $record, array $data): void {
                                abort_unless(Acceso::puede('CorregirPago'), 403);

                                $banco = $data['banco'] ?? null;

                                // En mixto, banco aplica a tarjeta/transferencia (igual
                                // que el POS). normalizarPagos descarta montos en cero
                                // y valida que la suma cuadre al centavo; si queda un
                                // solo método con monto, colapsa a ese método simple.
                                $pagos = $data['forma_pago'] === 'mixto' ? [
                                    ['metodo' => 'efectivo', 'monto' => (float) ($data['mixto_efectivo'] ?? 0)],
                                    ['metodo' => 'tarjeta', 'banco' => $banco, 'monto' => (float) ($data['mixto_tarjeta'] ?? 0)],
                                    ['metodo' => 'transferencia', 'banco' => $banco, 'monto' => (float) ($data['mixto_transferencia'] ?? 0)],
                                ] : null;

                                try {
                                    app(VentaService::class)->corregirPago($record, $data['forma_pago'], $banco, $pagos);
                                } catch (RestauranteException $e) {
                                    Notification::make()->title('No se pudo corregir')->body($e->getMessage())->danger()->send();

                                    return;
                                }

                                Notification::make()->title('Forma de pago corregida')->body('El cambio quedó en el Registro de Actividad.')->success()->send();
                            }),
                    )
                    ->toggleable(),
                // Solo aparece cuando el pago fue corregido: muestra cómo se
                // EMITIÓ la factura (snapshot); "Pago" muestra el interno actual.
                TextColumn::make('pago_original')
                    ->label('Pago original')
                    ->badge()
                    ->color('warning')
                    ->state(fn (Venta $record): ?string => $record->factura?->forma_pago !== null
                        && $record->factura->forma_pago !== $record->forma_pago
                            ? $record->factura->forma_pago
                            : null)
                    ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state))
                    ->placeholder('—')
                    ->tooltip('Forma de pago con la que se emitió la factura (la columna Pago muestra la corrección interna)')
                    ->toggleable(),
                TextColumn::make('cajero.name')->label('Cajero')->placeholder('—'),
                TextColumn::make('gravado')->label('Gravado')->money('HNL')->toggleable(),
                TextColumn::make('isv')->label('ISV')->money('HNL')->toggleable(),
                TextColumn::make('total')->label('Total')->money('HNL')->weight('bold')->sortable(),
            ])
            ->filters([
                SelectFilter::make('tipo')->options(['recibo' => 'Recibo', 'factura' => 'Factura']),
                Filter::make('hoy')->label('Solo hoy')->query(fn (Builder $q): Builder => $q->whereDate('vendida_at', today())),
            ])
            ->defaultSort('vendida_at', 'desc')
            ->recordActions([
                Action::make('pdf')
                    ->label('Factura')
                    ->icon('heroicon-o-printer')
                    // Reimpresión (pedido del cliente): si la venta tiene
                    // comanda, pregunta qué imprimir — factura, comanda o
                    // ambas. Si no tiene (ventas viejas o flag apagado), va
                    // directo sin modal. Acá el modal no estorba: reimprimir
                    // es poco frecuente, a diferencia del cobro en el POS.
                    ->modalHeading('Reimprimir documentos')
                    ->modalDescription(fn (Venta $record): string => 'Factura '.($record->factura->numero ?? '').' · L. '.number_format((float) $record->total, 2))
                    ->modalIcon('heroicon-o-printer')
                    ->modalWidth(Width::Medium)
                    ->modalSubmitActionLabel('Imprimir')
                    ->schema(fn (Venta $record): array => $record->comanda === null ? [] : [
                        ToggleButtons::make('documento')
                            ->hiddenLabel()
                            ->options([
                                'factura' => 'Factura',
                                'comanda' => 'Comanda',
                                'ambas'   => 'Factura + comanda',
                            ])
                            ->icons([
                                'factura' => 'heroicon-o-document-text',
                                'comanda' => 'heroicon-o-fire',
                                'ambas'   => 'heroicon-o-document-duplicate',
                            ])
                            ->colors([
                                'factura' => 'primary',
                                'comanda' => 'warning',
                                'ambas'   => 'success',
                            ])
                            ->inline()
                            ->default('factura')
                            ->required(),
                    ])
                    // Imprime directo (HTML instantáneo por iframe, igual que el
                    // POS) — sin pestaña nueva ni esperar a Chromium. El PDF
                    // sigue disponible vía WhatsApp.
                    ->action(function (Venta $record, array $data, $livewire): void {
                        $url = match ($data['documento'] ?? 'factura') {
                            'comanda' => $record->comanda?->urlTicket(),
                            'ambas'   => $record->factura?->urlDocumentos(),
                            default   => $record->factura?->urlTicket(),
                        };

                        $livewire->dispatch('imprimir-factura', url: $url);
                    })
                    ->visible(fn (Venta $record): bool => $record->factura !== null),
                Action::make('whatsapp')
                    ->label('WhatsApp')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('success')
                    ->url(fn (Venta $record): ?string => $record->factura?->urlWhatsApp(), shouldOpenInNewTab: true)
                    ->visible(fn (Venta $record): bool => $record->factura !== null),
                Action::make('anular_corregir')
                    ->label('Anular y corregir')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Anular y corregir')
                    ->modalDescription('Se anula esta factura (queda registrada con su motivo) y se reabre el pedido en el POS, ya cargado, para corregirlo y emitir una factura nueva.')
                    ->schema([
                        Textarea::make('motivo')->label('Motivo de la anulación')->required()->maxLength(255),
                    ])
                    ->visible(fn (Venta $record): bool => Acceso::puede('AnularFactura')
                        && $record->factura !== null
                        && ! $record->factura->anulada
                        && app(FacturacionSarService::class)->puedeAnular($record->factura))
                    ->action(function (Venta $record, array $data) {
                        abort_unless(Acceso::puede('AnularFactura'), 403);

                        app(FacturacionSarService::class)->anular($record->factura, $data['motivo'], (int) Auth::id());

                        Notification::make()->title('Factura anulada')->body('Corregí el pedido y volvé a facturar.')->success()->send();

                        return redirect(PuntoDeVenta::getUrl(['rehacer' => $record->id]));
                    }),
                Action::make('anular')
                    ->label('Solo anular')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Anular factura')
                    ->modalDescription('La factura no se borra: queda registrada como anulada con su motivo. El correlativo SAR no se reutiliza.')
                    ->schema([
                        Textarea::make('motivo')->label('Motivo de anulación')->required()->maxLength(255),
                    ])
                    ->visible(fn (Venta $record): bool => Acceso::puede('AnularFactura')
                        && $record->factura !== null
                        && ! $record->factura->anulada
                        && app(FacturacionSarService::class)->puedeAnular($record->factura))
                    ->action(function (Venta $record, array $data): void {
                        abort_unless(Acceso::puede('AnularFactura'), 403);

                        app(FacturacionSarService::class)->anular($record->factura, $data['motivo'], (int) Auth::id());

                        Notification::make()->title('Factura anulada')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVentas::route('/'),
        ];
    }
}
