<?php

declare(strict_types=1);

namespace App\Filament\Resources\Combos\Pages;

use App\Filament\Resources\Combos\ComboResource;
use App\Filament\Resources\CombosEspeciales\ComboEspecialResource;
use App\Models\ComboEspecial;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageCombos extends ManageRecords
{
    protected static string $resource = ComboResource::class;

    public function getSubheading(): ?string
    {
        return 'Precio automático del buffet: cuando el cajero arma una proteína + N complementos, '
            .'el POS aplica solo el precio del combo que calce (ej: Res + 2 = L.110). Gobierna casi '
            .'todas las ventas normales. Para platillos con nombre y precio fijo, usá "Platillos Completos".';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva regla de precio'),

            // Platillo completo con nombre (otra cosa que el combo-regla):
            // se crea desde acá mismo y se administra en "Platillos Completos".
            Action::make('nuevoComboEspecial')
                ->label('Nuevo platillo completo')
                ->icon('heroicon-o-gift')
                ->color('gray')
                ->schema(ComboEspecialResource::camposFormulario())
                ->action(function (array $data): void {
                    ComboEspecial::create($data);

                    Notification::make()
                        ->title('Platillo completo creado')
                        ->body('Ya aparece en el POS y en "Platillos Completos".')
                        ->success()
                        ->send();
                }),
        ];
    }
}
