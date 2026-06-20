<?php

declare(strict_types=1);

namespace App\Filament\Resources\CombosEspeciales\Pages;

use App\Filament\Resources\CombosEspeciales\ComboEspecialResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageComboEspeciales extends ManageRecords
{
    protected static string $resource = ComboEspecialResource::class;

    public function getSubheading(): ?string
    {
        return 'Promociones cerradas con nombre y precio fijo (ej: "Combo Familiar L.250"). El cajero las '
            .'cobra de un toque en el POS, sin armar complemento por complemento. Distintas de las "Reglas '
            .'de Precio", que calculan el precio del plato según cuántos complementos lleve.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo combo especial'),
        ];
    }
}
