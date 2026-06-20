<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tiers\Pages;

use App\Filament\Resources\Tiers\TierResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTiers extends ManageRecords
{
    protected static string $resource = TierResource::class;

    public function getSubheading(): ?string
    {
        return 'Agrupan proteínas que comparten el mismo precio de combo. Creá un nivel (ej: "Pescado"), '
            .'asignalo a la proteína en Productos y definí sus precios en "Reglas de Precio".';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo nivel de precio'),
        ];
    }
}
