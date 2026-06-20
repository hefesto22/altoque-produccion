<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\AlertaReposicion;
use App\Models\Comanda;
use App\Services\Cocina\ComandaService;
use App\Services\Cocina\ReposicionService;
use App\Support\Acceso;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Pantalla de cocina (KDS). Muestra las comandas para llevar / a domicilio
 * que aún no se entregan y las alertas de reposición. Se auto-refresca por
 * polling (sin websockets).
 */
class Cocina extends Page
{
    protected string $view = 'filament.pages.cocina';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-fire';

    protected static ?int $navigationSort = 1;

    public function getTitle(): string
    {
        return 'Cocina';
    }

    public static function getNavigationLabel(): string
    {
        return 'Cocina';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Cocina';
    }

    public static function canAccess(): bool
    {
        return Acceso::tieneAlguno(['administrador', 'gerente', 'cajero']);
    }

    /** @return array<int, Comanda> */
    public function comandas(): array
    {
        return Comanda::query()
            ->enCocina()
            ->get()
            ->all();
    }

    /** @return array<int, AlertaReposicion> */
    public function alertas(): array
    {
        return app(ReposicionService::class)->activas()->all();
    }

    public function preparando(int $comandaId): void
    {
        app(ComandaService::class)->marcarPreparando(Comanda::findOrFail($comandaId));
    }

    public function listo(int $comandaId): void
    {
        app(ComandaService::class)->marcarListo(Comanda::findOrFail($comandaId));
    }

    public function entregado(int $comandaId): void
    {
        app(ComandaService::class)->marcarEntregado(Comanda::findOrFail($comandaId));

        Notification::make()->title('Comanda entregada')->success()->send();
    }

    public function reponer(int $productoId): void
    {
        app(ReposicionService::class)->reponer($productoId, (int) Auth::id());

        Notification::make()->title('Reposición confirmada')->success()->send();
    }
}
