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
use App\Http\Controllers\FacturaPdfController;
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

// Ticket de comanda (80mm, HTML) — ruta FIRMADA: lo imprime el POS para cocina.
Route::get('/comandas/{comanda}/ticket', [ComandaTicketController::class, 'show'])
    ->name('comandas.ticket')
    ->middleware('signed');
