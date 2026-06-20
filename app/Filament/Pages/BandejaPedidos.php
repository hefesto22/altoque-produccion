<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\PedidoOnline;
use App\Services\Pedidos\PedidoOnlineService;
use App\Support\Acceso;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Bandeja de pedidos online en formato tarjetas: el personal revisa cada
 * pedido pendiente y lo confirma (genera venta + comanda) o lo rechaza,
 * de un vistazo. Se auto-refresca para ver pedidos nuevos.
 */
class BandejaPedidos extends Page
{
    protected string $view = 'filament.pages.bandeja-pedidos';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?int $navigationSort = 2;

    public function getTitle(): string
    {
        return 'Pedidos Online';
    }

    public static function getNavigationLabel(): string
    {
        return 'Pedidos Online';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Cocina';
    }

    public static function getNavigationBadge(): ?string
    {
        $n = PedidoOnline::query()->where('estado', 'pendiente')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function canAccess(): bool
    {
        return Acceso::tieneAlguno(['administrador', 'gerente', 'cajero']);
    }

    /** @return array<int, PedidoOnline> */
    public function pendientes(): array
    {
        return PedidoOnline::query()->pendientes()->get()->all();
    }

    public function confirmar(int $id): void
    {
        $pedido = PedidoOnline::find($id);

        if ($pedido === null || $pedido->estado !== 'pendiente') {
            return;
        }

        app(PedidoOnlineService::class)->confirmar($pedido, (int) Auth::id());

        Notification::make()->title("Pedido {$pedido->numero} confirmado")->body('Enviado a cocina.')->success()->send();
    }

    public function rechazar(int $id, string $motivo = 'Rechazado por el personal'): void
    {
        $pedido = PedidoOnline::find($id);

        if ($pedido === null || $pedido->estado !== 'pendiente') {
            return;
        }

        app(PedidoOnlineService::class)->rechazar($pedido, $motivo !== '' ? $motivo : 'Sin motivo');

        Notification::make()->title("Pedido {$pedido->numero} rechazado")->warning()->send();
    }
}
