<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cotizaciones\Pages;

use App\Filament\Resources\Cotizaciones\CotizacionResource;
use App\Services\Eventos\CotizadorEventos;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCotizacion extends EditRecord
{
    protected static string $resource = CotizacionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /** Los totales se recalculan del lado del servidor en cada guardado. */
    protected function afterSave(): void
    {
        CotizadorEventos::make()->recalcular($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
