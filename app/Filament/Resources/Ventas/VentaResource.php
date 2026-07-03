<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas;

use App\Filament\Pages\PuntoDeVenta;
use App\Filament\Resources\Ventas\Pages\ListVentas;
use App\Models\Venta;
use App\Services\Facturacion\FacturacionSarService;
use App\Support\Acceso;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
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

    public static function canViewAny(): bool
    {
        // Por permiso, no por lista de roles: quitarle view_any_venta a un
        // rol desde la pantalla de Roles le oculta esta sección sin tocar código.
        return Acceso::puede('view_any_venta');
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
            ->select(['id', 'tipo', 'forma_pago', 'banco', 'numero_recibo', 'rtn_cliente', 'gravado', 'exento', 'isv', 'total', 'cajero_id', 'vendida_at'])
            ->with(['cajero:id,name', 'factura']);
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
                    // Imprime directo (HTML instantáneo por iframe, igual que el
                    // POS) — sin pestaña nueva ni esperar a Chromium. El PDF
                    // sigue disponible vía WhatsApp.
                    ->action(fn (Venta $record, $livewire) => $livewire->dispatch('imprimir-factura', url: $record->factura?->urlTicket()))
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
