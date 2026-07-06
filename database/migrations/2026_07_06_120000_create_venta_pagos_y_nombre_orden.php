<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pago mixto: una venta puede pagarse con varios métodos a la vez
 * (efectivo + tarjeta + transferencia). Cada pago es una fila en
 * venta_pagos; ventas.forma_pago queda como resumen ('mixto' si hay
 * más de un método). El corte de caja agrega desde venta_pagos para
 * que el efectivo esperado en gaveta sea exacto al centavo.
 *
 * También agrega ventas.nombre_orden: el nombre del cliente de la
 * ORDEN (el que sale en la comanda), distinto del nombre fiscal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_pagos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venta_id')->constrained()->cascadeOnDelete();
            $table->string('metodo', 20);           // efectivo | tarjeta | transferencia
            $table->string('banco')->nullable();    // solo tarjeta/transferencia
            $table->decimal('monto', 12, 2);
            $table->timestamps();

            // Corte de caja: sumar por método dentro de un conjunto de ventas.
            $table->index(['venta_id']);
            $table->index(['metodo']);
        });

        // Defensa en profundidad a nivel de BD.
        DB::statement("ALTER TABLE venta_pagos ADD CONSTRAINT venta_pagos_metodo_valido CHECK (metodo IN ('efectivo', 'tarjeta', 'transferencia'))");
        DB::statement('ALTER TABLE venta_pagos ADD CONSTRAINT venta_pagos_monto_positivo CHECK (monto > 0)');

        // El enum de Laravel es un CHECK en Postgres: se amplía para 'mixto'.
        DB::statement('ALTER TABLE ventas DROP CONSTRAINT IF EXISTS ventas_forma_pago_check');
        DB::statement("ALTER TABLE ventas ADD CONSTRAINT ventas_forma_pago_check CHECK (forma_pago IN ('efectivo', 'tarjeta', 'transferencia', 'mixto'))");

        Schema::table('ventas', function (Blueprint $table): void {
            // Nombre de la orden (comanda), independiente del nombre fiscal.
            $table->string('nombre_orden')->nullable()->after('nombre_cliente');
        });

        // Backfill: toda venta YA PAGADA queda con su pago único equivalente.
        // Las pendientes generan su fila al momento de cobrarse.
        DB::statement("
            INSERT INTO venta_pagos (venta_id, metodo, banco, monto, created_at, updated_at)
            SELECT id, forma_pago, banco, total, now(), now()
            FROM ventas
            WHERE pagada = true AND forma_pago IN ('efectivo', 'tarjeta', 'transferencia')
        ");
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table): void {
            $table->dropColumn('nombre_orden');
        });

        DB::statement('ALTER TABLE ventas DROP CONSTRAINT IF EXISTS ventas_forma_pago_check');
        DB::statement("ALTER TABLE ventas ADD CONSTRAINT ventas_forma_pago_check CHECK (forma_pago IN ('efectivo', 'tarjeta', 'transferencia'))");

        Schema::dropIfExists('venta_pagos');
    }
};
