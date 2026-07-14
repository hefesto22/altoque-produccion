<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cotizaciones\Pages;

use App\Filament\Resources\Cotizaciones\CotizacionResource;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Services\Eventos\CotizadorEventos;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCotizacion extends EditRecord
{
    protected static string $resource = CotizacionResource::class;

    /**
     * Candado duro (aunque alguien entre por URL directa): una cotización
     * COMPLETADA ya tiene factura SAR emitida y no se edita — la factura
     * debe reflejar exactamente lo que el cliente aceptó.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var Cotizacion $cotizacion */
        $cotizacion = $this->record;

        abort_if($cotizacion->estado === 'completada', 403, 'Cotización completada: ya tiene factura emitida y no se edita.');
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /** Los totales se recalculan del lado del servidor en cada guardado. */
    protected function afterSave(): void
    {
        $cotizador = CotizadorEventos::make();
        $cotizador->recalcular($this->record);
        $cotizador->aprenderCatalogo($this->record);

        if ($this->record->cliente_rtn !== null && $this->record->cliente_rtn !== '') {
            Cliente::registrar($this->record->cliente_rtn, $this->record->cliente_nombre);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
