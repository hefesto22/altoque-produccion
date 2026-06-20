<?php

declare(strict_types=1);

namespace App\Filament\Resources\CorteCajas\Pages;

use App\Filament\Resources\CorteCajas\CorteCajaResource;
use Filament\Resources\Pages\ListRecords;

class ListCorteCajas extends ListRecords
{
    protected static string $resource = CorteCajaResource::class;
}
