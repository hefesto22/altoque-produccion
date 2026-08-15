<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPrepago\Pages;

use App\Filament\Resources\CuentasPrepago\CuentaPrepagoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCuentasPrepago extends ListRecords
{
    protected static string $resource = CuentaPrepagoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva cuenta'),
        ];
    }
}
