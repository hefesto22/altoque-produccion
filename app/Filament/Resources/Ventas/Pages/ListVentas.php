<?php

declare(strict_types=1);

namespace App\Filament\Resources\Ventas\Pages;

use App\Filament\Resources\Ventas\VentaResource;
use App\Models\PeriodoFiscal;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ListVentas extends ListRecords
{
    protected static string $resource = VentaResource::class;

    /**
     * Tabs de estado fiscal:
     *  - Vigentes: no anuladas y de un período aún NO declarado (anulables).
     *  - Declaradas: de un período ya declarado al SAR (bloqueadas).
     *  - Anuladas: facturas anuladas.
     *
     * Importante: el parámetro de la closure DEBE llamarse $query — así lo
     * inyecta Filament por nombre. Si se llama distinto, Filament resuelve
     * un Builder sin modelo y rompe. Todo en whereRaw para evitar closures
     * anidados/relaciones que dependan del modelo.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        // OR de rangos de fechas de los períodos declarados (índice-friendly).
        $partes = [];
        $bind = [];

        foreach (PeriodoFiscal::query()->where('estado', 'declarado')->get(['anio', 'mes']) as $p) {
            $inicio = Carbon::create($p->anio, $p->mes, 1)->startOfMonth();
            $partes[] = 'vendida_at between ? and ?';
            $bind[] = $inicio->toDateTimeString();
            $bind[] = $inicio->copy()->endOfMonth()->toDateTimeString();
        }

        $enDeclarado = $partes === [] ? null : '('.implode(' or ', $partes).')';

        $sqlAnulada = 'exists (select 1 from facturas where facturas.venta_id = ventas.id and facturas.anulada = true)';

        return [
            'activas' => Tab::make('Vigentes')
                ->icon('heroicon-o-check-circle')
                ->modifyQueryUsing(function (Builder $query) use ($enDeclarado, $bind, $sqlAnulada): Builder {
                    $query->whereRaw("not {$sqlAnulada}");

                    if ($enDeclarado !== null) {
                        $query->whereRaw("not {$enDeclarado}", $bind);
                    }

                    return $query;
                }),

            'declaradas' => Tab::make('Declaradas')
                ->icon('heroicon-o-lock-closed')
                ->modifyQueryUsing(function (Builder $query) use ($enDeclarado, $bind, $sqlAnulada): Builder {
                    $query->whereRaw("not {$sqlAnulada}");
                    $enDeclarado !== null
                        ? $query->whereRaw($enDeclarado, $bind)
                        : $query->whereRaw('1 = 0');

                    return $query;
                }),

            'anuladas' => Tab::make('Anuladas')
                ->icon('heroicon-o-x-circle')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereRaw($sqlAnulada)),

            'todas' => Tab::make('Todas'),
        ];
    }
}
