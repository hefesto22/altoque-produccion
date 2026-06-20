<?php

declare(strict_types=1);

namespace App\Filament\Resources\PedidosOnline\Pages;

use App\Filament\Resources\PedidosOnline\PedidoOnlineResource;
use Filament\Resources\Pages\ListRecords;

class ListPedidosOnline extends ListRecords
{
    protected static string $resource = PedidoOnlineResource::class;

    /** Auto-refresco para ver pedidos nuevos. */
    protected ?string $pollingInterval = '10s';
}
