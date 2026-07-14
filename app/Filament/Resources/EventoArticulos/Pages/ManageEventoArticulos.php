<?php

declare(strict_types=1);

namespace App\Filament\Resources\EventoArticulos\Pages;

use App\Filament\Resources\EventoArticulos\EventoArticuloResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageEventoArticulos extends ManageRecords
{
    protected static string $resource = EventoArticuloResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo artículo'),
        ];
    }
}
