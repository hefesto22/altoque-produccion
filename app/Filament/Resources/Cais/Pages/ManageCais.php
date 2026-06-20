<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cais\Pages;

use App\Filament\Resources\Cais\CaiResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCais extends ManageRecords
{
    protected static string $resource = CaiResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Cargar rango CAI'),
        ];
    }
}
