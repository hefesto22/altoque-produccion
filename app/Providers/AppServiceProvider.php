<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Contracts\CalculaImpuestos;
use App\Listeners\RecordUserLogin;
use App\Policies\ActivityPolicy;
use App\Services\Pos\CalculadorVenta;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Calculador de impuestos: la tasa de ISV viene de config, nunca
        // hardcodeada. Único lugar donde se resuelve la implementación.
        $this->app->bind(CalculaImpuestos::class, function (): CalculadorVenta {
            return new CalculadorVenta(
                tasaIsv: (float) config('honduras.impuestos.isv.tasa_general'),
            );
        });
    }

    public function boot(): void
    {
        // ─── Localización global ────────────────────────────────────────
        // Carbon usa el locale para diffForHumans, translatedFormat, etc.
        // Sin esto, las fechas mostrarán "Monday April 26 2026" en vez
        // de "lunes 26 de abril de 2026".
        $locale = (string) config('app.locale', 'es');
        Carbon::setLocale($locale);
        CarbonImmutable::setLocale($locale);

        // setlocale() afecta a strftime() y formatos del sistema PHP.
        // Útil cuando código legacy usa estas funciones.
        @setlocale(LC_TIME, 'es_HN.UTF-8', 'es_ES.UTF-8', 'es_ES', 'es');
        @setlocale(LC_MONETARY, 'es_HN.UTF-8', 'es_ES.UTF-8', 'es_ES', 'es');

        // ─── Filament: forzar locale español al renderizar el panel ─────
        // Garantiza que mensajes, validaciones y acciones de Filament
        // siempre estén en español, sin importar el header Accept-Language
        // del browser del usuario.
        FilamentView::registerRenderHook(
            'panels::body.start',
            fn (): string => '',
        );
        // El locale se setea automáticamente al servir Filament.
        Filament::serving(function (): void {
            app()->setLocale((string) config('app.locale', 'es'));
        });

        // ─── Impresión directa global ───────────────────────────────────
        // Script del iframe de impresión disponible en TODO el panel: el
        // POS y el listado de Ventas despachan 'imprimir-factura' /
        // 'imprimir-comanda' y esto los atiende sin abrir pestañas.
        FilamentView::registerRenderHook(
            'panels::body.end',
            fn (): string => view('filament.partials.imprimir-script')->render(),
        );

        // ─── Policies de modelos del vendor ─────────────────────────────
        // Laravel solo auto-descubre policies de modelos en App\Models.
        // Activity es de Spatie, así que su policy hay que registrarla a
        // mano — sin esto, ActivityLogResource queda SIN control de acceso
        // y cualquier usuario del panel ve el registro de actividad.
        // Con la policy activa, solo quien tenga ViewAny:Activity lo ve
        // (hoy: únicamente super_admin).
        Gate::policy(Activity::class, ActivityPolicy::class);

        // ─── Eventos ────────────────────────────────────────────────────
        Event::listen(Login::class, RecordUserLogin::class);
    }
}
