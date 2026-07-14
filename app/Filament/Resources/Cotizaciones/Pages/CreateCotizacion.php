<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cotizaciones\Pages;

use App\Filament\Resources\Cotizaciones\CotizacionResource;
use App\Models\Cliente;
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

    /**
     * Los totales se calculan del lado del servidor, nunca del formulario.
     * Si el cliente trae RTN, se registra/actualiza en Clientes (mismo
     * criterio que la facturación: el RTN es la llave).
     */
    protected function afterCreate(): void
    {
        CotizadorEventos::make()->recalcular($this->record);

        if ($this->record->cliente_rtn !== null && $this->record->cliente_rtn !== '') {
            Cliente::registrar($this->record->cliente_rtn, $this->record->cliente_nombre);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
