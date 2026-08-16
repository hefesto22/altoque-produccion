<?php

declare(strict_types=1);

namespace App\Filament\Resources\PagosSistema\Pages;

use App\Filament\Resources\PagosSistema\PagoSistemaResource;
use App\Filament\Resources\PagosSistema\Widgets\ResumenContrato;
use App\Services\Pagos\PagoSistemaService;
use Filament\Resources\Pages\ListRecords;

class ListPagosSistema extends ListRecords
{
    protected static string $resource = PagoSistemaResource::class;

    /**
     * El plan se materializa al entrar, no en un seeder: si mañana se pacta
     * otra etapa, basta con tocar config/cobro.php y abrir la pantalla.
     * sincronizarPlan() sale por una sola consulta cuando ya está todo.
     */
    public function mount(): void
    {
        parent::mount();

        app(PagoSistemaService::class)->sincronizarPlan();
    }

    protected function getHeaderWidgets(): array
    {
        return [ResumenContrato::class];
    }

    public function getSubheading(): ?string
    {
        return 'Lo que el restaurante paga por el sistema y el servidor. No es plata del negocio: '
            .'no entra a cortes de caja ni a los libros fiscales.';
    }
}
