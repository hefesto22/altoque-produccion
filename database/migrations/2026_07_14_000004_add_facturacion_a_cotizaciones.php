<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobro y facturación de eventos (decisiones confirmadas con Mauricio):
 *
 *  - Los abonos parciales (anticipo hoy, resto después) se registran en
 *    `cotizacion_pagos` SIN documento fiscal — son pagos internos.
 *  - Al completar el evento se emite UNA factura SAR por el total, vía
 *    el flujo normal de ventas (entra al corte del turno, libros y
 *    declaración ISV). `cotizaciones.venta_id` guarda esa venta: una
 *    cotización facturada queda congelada y no se factura dos veces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotizacion_pagos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cotizacion_id')->constrained('cotizaciones')->cascadeOnDelete();
            $table->decimal('monto', 12, 2);
            $table->string('forma_pago', 15); // efectivo|tarjeta|transferencia
            $table->string('banco')->nullable();
            $table->string('notas')->nullable();
            $table->foreignId('recibido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recibido_at');
            $table->timestamps();
        });

        Schema::table('cotizaciones', function (Blueprint $table): void {
            $table->foreignId('venta_id')->nullable()->constrained('ventas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('venta_id');
        });

        Schema::dropIfExists('cotizacion_pagos');
    }
};
