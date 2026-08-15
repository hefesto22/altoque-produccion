<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Filament v4 toma control de "/" porque el panel está configurado con
| ->path('/') en AdminPanelProvider. NO definir aquí Route::get('/') —
| Filament lo perderá si la ruta web tiene mayor prioridad.
|
| Este archivo queda disponible para rutas custom adicionales (webhooks,
| callbacks OAuth, endpoints públicos puntuales) que NO conflictúen con
| las rutas de Filament.
|
| Las rutas internas del panel (/login, /dashboard, /users, /shield/roles,
| /horizon, etc.) las gestiona Filament automáticamente.
*/

use App\Http\Controllers\ComandaTicketController;
use App\Http\Controllers\CorteTicketController;
use App\Http\Controllers\CotizacionPdfController;
use App\Http\Controllers\CuentaEstadoController;
use App\Http\Controllers\FacturaPdfController;
use App\Http\Controllers\TandaImpresionController;
use App\Http\Controllers\VerificacionController;
use App\Livewire\MenuPantalla;
use App\Livewire\PedirOnline;
use Illuminate\Support\Facades\Route;

// Página pública de pedidos en línea (sin login).
Route::get('/pedir', PedirOnline::class)->name('pedir');

// Pantalla pública de menú (menu board para la TV del local).
Route::get('/menu', MenuPantalla::class)->name('menu');

// PDF de factura — ruta pública FIRMADA (compartible por WhatsApp, no adivinable).
Route::get('/facturas/{factura}/pdf', [FacturaPdfController::class, 'show'])
    ->name('facturas.pdf')
    ->middleware('signed');

// Verificación pública de autenticidad (destino del QR). El hash es el secreto.
Route::get('/verificar/{hash}', [VerificacionController::class, 'show'])
    ->name('facturas.verificar');

// Factura como HTML (impresión instantánea en caja, sin Chromium) — FIRMADA.
Route::get('/facturas/{factura}/ticket', [FacturaPdfController::class, 'ticket'])
    ->name('facturas.ticket')
    ->middleware('signed');

// Factura + comanda en un solo documento imprimible — FIRMADA.
Route::get('/facturas/{factura}/documentos', [FacturaPdfController::class, 'documentos'])
    ->name('facturas.documentos')
    ->middleware('signed');

// Ticket de comanda (80mm, HTML) — ruta FIRMADA: lo imprime el POS para cocina.
Route::get('/comandas/{comanda}/ticket', [ComandaTicketController::class, 'show'])
    ->name('comandas.ticket')
    ->middleware('signed');

// Ticket del corte de caja (80mm, HTML) — FIRMADA: se imprime solo al cerrar
// el turno y se reimprime desde Cortes de Caja.
Route::get('/cortes/{corte}/ticket', [CorteTicketController::class, 'show'])
    ->name('cortes.ticket')
    ->middleware('signed');

// Tanda de impresión: varios tickets pendientes en UN solo documento (un
// diálogo, un envío; la térmica corta entre uno y otro) — FIRMADA.
Route::get('/impresiones/tanda', [TandaImpresionController::class, 'show'])
    ->name('impresiones.tanda')
    ->middleware('signed');

// PDF de cotización de evento — ruta pública FIRMADA. Levanta Chromium: ya no
// se enlaza desde ningún lado, lo que se comparte es el HTML de abajo.
Route::get('/cotizaciones/{cotizacion}/pdf', [CotizacionPdfController::class, 'show'])
    ->name('cotizaciones.pdf')
    ->middleware('signed');

// Cotización como HTML (apertura instantánea, sin Chromium) — FIRMADA. Es el
// link que se manda por WhatsApp y el que abre el botón "Ver" de la tabla.
Route::get('/cotizaciones/{cotizacion}/ver', [CotizacionPdfController::class, 'ver'])
    ->name('cotizaciones.ver')
    ->middleware('signed');

// Estado de una cuenta prepago — pública, FIRMADA y por token. El cliente la
// abre desde su WhatsApp para ver cuánto le queda y en qué se fue.
Route::get('/cuentas/{token}/estado', [CuentaEstadoController::class, 'estado'])
    ->name('cuentas.estado')
    ->middleware('signed');
