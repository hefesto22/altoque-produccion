<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\AvisoPago;
use App\Support\Acceso;
use App\Support\CobroMensual;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Franja de aviso del pago mensual del sistema.
 *
 * Se engancha arriba del panel con un render hook (ver AdminPanelProvider) y
 * solo la ve quien tenga el permiso `VerAvisoPago` — hoy, el gerente. El
 * botón "Recordatorio recibido" la esconde hasta el día 1 del mes siguiente,
 * dejando registrado quién y cuándo la marcó.
 */
class AvisoPagoMensual extends Component
{
    public function marcarRecibido(): void
    {
        if (! $this->debeVerse()) {
            return;
        }

        AvisoPago::query()->firstOrCreate(
            ['periodo' => CobroMensual::periodo(now())->toDateString()],
            ['user_id' => Auth::id(), 'confirmado_en' => now()],
        );

        Notification::make()
            ->title('Recordatorio recibido')
            ->body('El aviso vuelve a aparecer el 1 del mes que viene.')
            ->success()
            ->seconds(3)
            ->send();
    }

    public function render(): View
    {
        $ahora = now();
        $periodo = CobroMensual::periodo($ahora);

        return view('livewire.aviso-pago-mensual', [
            'visible'  => $this->debeVerse(),
            'monto'    => CobroMensual::monto($periodo),
            'concepto' => CobroMensual::concepto($periodo),
            'mes'      => ucfirst($periodo->translatedFormat('F \d\e Y')),
        ]);
    }

    /** Permiso + fecha/hora + que no esté ya marcado, en ese orden. */
    private function debeVerse(): bool
    {
        if (! Acceso::puede('VerAvisoPago')) {
            return false;
        }

        $ahora = now();
        $periodo = CobroMensual::periodo($ahora);

        return CobroMensual::toca($ahora)
            && ! CobroMensual::yaPagado($periodo)
            && ! CobroMensual::yaConfirmado($periodo);
    }
}
