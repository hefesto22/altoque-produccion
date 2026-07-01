<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pago diferido ("pagar después") + costo de viaje del repartidor.
 *
 *  - pagada: una venta nace pagada (cobro al momento) o pendiente (se manda
 *    a cocina y se cobra al entregar/recoger). Solo cuando se cobra entra al
 *    corte de caja del turno en que se cobró.
 *  - costo_viaje: monto que cobra el repartidor por el domicilio. Es CONTROL
 *    INTERNO para pagarle al repartidor — NO va en la factura ni en el ISV.
 *
 * Aditiva: default pagada=true => las ventas existentes quedan como pagadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table): void {
            $table->boolean('pagada')->default(true)->after('numero_orden');
            $table->timestamp('pagada_at')->nullable()->after('pagada');
            $table->decimal('costo_viaje', 12, 2)->default(0)->after('total')
                ->comment('Interno: pago al repartidor. NO entra en el total fiscal ni en la factura.');

            // Pendientes por cobrar: consulta frecuente en el POS.
            $table->index(['pagada', 'vendida_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table): void {
            $table->dropColumn(['pagada', 'pagada_at', 'costo_viaje']);
        });
    }
};
