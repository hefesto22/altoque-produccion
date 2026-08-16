<?php

declare(strict_types=1);

namespace App\Filament\Resources\PagosSistema\Widgets;

use App\Models\PagoSistema;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Los cuatro números del contrato, arriba de la lista.
 *
 * Vive bajo el Resource y no en app/Filament/Widgets a propósito: ahí lo
 * descubriría el panel y terminaría en el Escritorio, a la vista de todos.
 * Este dato es del gerente y del super_admin, de nadie más.
 */
class ResumenContrato extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $r = PagoSistema::resumen();

        $proxima = $r['proxima'];

        return [
            Stat::make('Contrato', 'L. '.number_format($r['total'], 2))
                ->description($r['cuotas'].' cuotas mensuales')
                ->descriptionIcon('heroicon-o-document-text')
                ->color('gray'),

            Stat::make('Pagado', 'L. '.number_format($r['pagado'], 2))
                ->description($r['pagadas'].' de '.$r['cuotas'].' cuotas')
                ->descriptionIcon('heroicon-o-check-circle')
                ->color('success'),

            Stat::make('Falta', 'L. '.number_format($r['saldo'], 2))
                ->description($r['atrasadas'] > 0
                    ? $r['atrasadas'].' cuota(s) atrasada(s)'
                    : 'Sin atrasos')
                ->descriptionIcon($r['atrasadas'] > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-hand-thumb-up')
                ->color($r['atrasadas'] > 0 ? 'danger' : 'warning'),

            Stat::make('Próxima cuota', $proxima !== null ? 'L. '.number_format((float) $proxima->monto, 2) : '—')
                ->description($proxima !== null ? $proxima->mesLabel() : 'Contrato completo')
                ->descriptionIcon('heroicon-o-calendar-days')
                ->color($proxima !== null ? 'info' : 'success'),
        ];
    }
}
