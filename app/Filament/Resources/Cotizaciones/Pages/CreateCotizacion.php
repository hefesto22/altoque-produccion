<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cotizaciones\Pages;

use App\Filament\Resources\Cotizaciones\CotizacionResource;
use App\Services\Eventos\CotizadorEventos;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateCotizacion extends CreateRecord
{
    protected static string $resource = CotizacionResource::class;

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['creado_por'] = Auth::id();

        return $data;
    }

    /** Los totales se calculan del lado del servidor, nunca del formulario. */
    protected function afterCreate(): void
    {
        CotizadorEventos::make()->recalcular($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
