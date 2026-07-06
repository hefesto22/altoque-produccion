<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot de la forma de pago EN LA FACTURA al momento de emitirse.
 *
 * La reimpresión debe ser idéntica al documento que se llevó el cliente:
 * si después se corrige la forma de pago (control interno, venta_pagos),
 * la factura impresa NO cambia — imprime siempre este snapshot congelado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table): void {
            $table->string('forma_pago', 20)->nullable()->after('detallada');
            // Desglose del pago al emitir (para mixto): [{metodo, banco, monto}, ...]
            $table->jsonb('pagos_detalle')->nullable()->after('forma_pago');
        });

        // Backfill de facturas existentes con el pago actual de su venta
        // (el mejor dato disponible; a partir de ahora queda congelado).
        DB::statement('UPDATE facturas f SET forma_pago = v.forma_pago FROM ventas v WHERE v.id = f.venta_id');

        DB::statement("
            UPDATE facturas f SET pagos_detalle = p.detalle
            FROM (
                SELECT venta_id,
                       jsonb_agg(jsonb_build_object('metodo', metodo, 'banco', banco, 'monto', monto) ORDER BY id) AS detalle
                FROM venta_pagos
                GROUP BY venta_id
            ) p
            WHERE p.venta_id = f.venta_id
        ");
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table): void {
            $table->dropColumn(['forma_pago', 'pagos_detalle']);
        });
    }
};
