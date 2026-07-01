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
            ->with('venta:id,numero_orden,tipo_orden,costo_viaje')
            ->enCocina()
            ->get()
            ->all();
    }

    /** @return array<int, AlertaReposicion> */
    public function alertas(): array
    {
        return app(ReposicionService::class)->activas()->all();
    }

    // ── Cambio de tipo de entrega (llevar ↔ domicilio) ──────────────────

    /** Comanda a la que se le está capturando dirección para pasar a domicilio. */
    public ?int $comandaADomicilio = null;

    public string $entregaNombre = '';

    public string $entregaTelefono = '';

    public string $entregaIdentidad = '';

    public string $entregaDireccion = '';

    public string $entregaCostoViaje = '';

    /** Domicilio → Para llevar: directo, no necesita datos extra. */
    public function pasarALlevar(int $comandaId): void
    {
        app(ComandaService::class)->cambiarTipo(Comanda::findOrFail($comandaId), 'llevar');

        Notification::make()->title('Cambiado a "Para llevar"')->success()->send();
    }

    /** Abre el mini-formulario para capturar la dirección antes de pasar a domicilio. */
    public function pedirDomicilio(int $comandaId): void
    {
        $comanda = Comanda::with('venta:id,costo_viaje')->findOrFail($comandaId);

        $this->comandaADomicilio = $comanda->id;
        $this->entregaNombre = $comanda->cliente_nombre ?? '';
        $this->entregaTelefono = $comanda->cliente_telefono ?? '';
        $this->entregaIdentidad = $comanda->cliente_identidad ?? '';
        $this->entregaDireccion = $comanda->cliente_direccion ?? '';
        $viaje = (float) ($comanda->venta?->costo_viaje ?? 0);
        $this->entregaCostoViaje = $viaje > 0 ? (string) $viaje : '';
    }

    public function cancelarDomicilio(): void
    {
        $this->comandaADomicilio = null;
        $this->entregaNombre = $this->entregaTelefono = $this->entregaIdentidad = $this->entregaDireccion = '';
        $this->entregaCostoViaje = '';
    }

    /** Confirma el paso a domicilio. Teléfono y dirección son obligatorios. */
    public function confirmarDomicilio(): void
    {
        if ($this->comandaADomicilio === null) {
            return;
        }

        if (trim($this->entregaTelefono) === '' || trim($this->entregaDireccion) === '') {
            Notification::make()
                ->title('Faltan datos de entrega')
                ->body('Teléfono y dirección son obligatorios para domicilio.')
                ->warning()
                ->send();

            return;
        }

        app(ComandaService::class)->cambiarTipo(
            Comanda::findOrFail($this->comandaADomicilio),
            'domicilio',
            [
                'nombre'    => $this->entregaNombre,
                'telefono'  => $this->entregaTelefono,
                'identidad' => $this->entregaIdentidad,
                'direccion' => $this->entregaDireccion,
            ],
            is_numeric($this->entregaCostoViaje) ? (float) $this->entregaCostoViaje : 0.0,
        );

        $this->cancelarDomicilio();

        Notification::make()->title('Cambiado a "A domicilio"')->success()->send();
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
