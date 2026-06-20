<?php

declare(strict_types=1);

namespace App\Filament\Resources\PedidosOnline;

use App\Filament\Resources\PedidosOnline\Pages\ListPedidosOnline;
use App\Models\PedidoOnline;
use App\Services\Pedidos\PedidoOnlineService;
use App\Support\Acceso;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class PedidoOnlineResource extends Resource
{
    protected static ?string $model = PedidoOnline::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $modelLabel = 'Pedido online';

    protected static ?string $pluralModelLabel = 'Pedidos Online';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Cocina';
    }

    public static function canViewAny(): bool
    {
        return Acceso::tieneAlguno(['administrador', 'gerente', 'cajero']);
    }

    /** La gestión diaria se hace en la página de tarjetas "Pedidos Online". */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = PedidoOnline::query()->where('estado', 'pendiente')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('numero')->label('Pedido')->weight('bold')->searchable(),
                TextColumn::make('created_at')->label('Recibido')->since(),
                TextColumn::make('tipo')->label('Tipo')->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'domicilio' ? 'Domicilio' : 'Retiro')
                    ->color(fn (string $state): string => $state === 'domicilio' ? 'info' : 'gray'),
                TextColumn::make('cliente_nombre')->label('Cliente')->description(fn (PedidoOnline $record): string => $record->cliente_telefono),
                TextColumn::make('metodo_pago')->label('Pago')->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                TextColumn::make('total')->label('Total')->money('HNL'),
                TextColumn::make('estado')->label('Estado')->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'confirmado' => 'success', 'rechazado' => 'danger', default => 'warning',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('estado')->options([
                    'pendiente' => 'Pendiente', 'confirmado' => 'Confirmado', 'rechazado' => 'Rechazado',
                ])->default('pendiente'),
            ])
            ->recordActions([
                Action::make('detalle')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (PedidoOnline $record): string => "Pedido {$record->numero}")
                    ->modalContent(fn (PedidoOnline $record) => view('filament.pedido-detalle', ['pedido' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar'),
                Action::make('comprobante')
                    ->label('Comprobante')
                    ->icon('heroicon-o-photo')
                    ->color('gray')
                    ->url(fn (PedidoOnline $record): ?string => $record->comprobante_path ? Storage::disk('public')->url($record->comprobante_path) : null, shouldOpenInNewTab: true)
                    ->visible(fn (PedidoOnline $record): bool => $record->comprobante_path !== null),
                Action::make('confirmar')
                    ->label('Confirmar')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Se registrará la venta y se enviará la comanda a cocina.')
                    ->visible(fn (PedidoOnline $record): bool => $record->estado === 'pendiente')
                    ->action(function (PedidoOnline $record): void {
                        app(PedidoOnlineService::class)->confirmar($record, (int) Auth::id());
                        Notification::make()->title('Pedido confirmado')->body('Enviado a cocina.')->success()->send();
                    }),
                Action::make('rechazar')
                    ->label('Rechazar')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([Textarea::make('motivo')->label('Motivo')->required()->maxLength(255)])
                    ->visible(fn (PedidoOnline $record): bool => $record->estado === 'pendiente')
                    ->action(function (PedidoOnline $record, array $data): void {
                        app(PedidoOnlineService::class)->rechazar($record, $data['motivo']);
                        Notification::make()->title('Pedido rechazado')->warning()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPedidosOnline::route('/'),
        ];
    }
}
