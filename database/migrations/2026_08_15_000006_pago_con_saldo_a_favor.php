<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Método de pago `saldo`: la venta se cobra contra la cuenta prepago del
 * cliente.
 *
 * La venta es una venta normal — factura SAR, correlativo, ISV y libros
 * iguales. Lo único distinto es de dónde sale la plata: el cliente ya la
 * había depositado.
 *
 * ⚠️ Para el ARQUEO esto es clave: `saldo` es su propio método, así que el
 * corte no lo suma como efectivo en gaveta. Si se hubiera reusado 'efectivo',
 * la caja aparecería con dinero que nunca entró ese día.
 *
 * `corte_cajas.total_saldo` existe para que el corte cuadre a la vista: sin
 * esa línea, "Ventas 500 / Efectivo 200" deja 300 sin explicar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE venta_pagos DROP CONSTRAINT IF EXISTS venta_pagos_metodo_valido');
        DB::statement("ALTER TABLE venta_pagos ADD CONSTRAINT venta_pagos_metodo_valido CHECK (metodo IN ('efectivo', 'tarjeta', 'transferencia', 'saldo'))");

        DB::statement('ALTER TABLE ventas DROP CONSTRAINT IF EXISTS ventas_forma_pago_check');
        DB::statement("ALTER TABLE ventas ADD CONSTRAINT ventas_forma_pago_check CHECK (forma_pago IN ('efectivo', 'tarjeta', 'transferencia', 'mixto', 'saldo'))");

        Schema::table('corte_cajas', function (Blueprint $table): void {
            $table->decimal('total_saldo', 12, 2)->default(0)->after('total_transferencia');
        });
    }

    public function down(): void
    {
        Schema::table('corte_cajas', function (Blueprint $table): void {
            $table->dropColumn('total_saldo');
        });

        DB::statement('ALTER TABLE venta_pagos DROP CONSTRAINT IF EXISTS venta_pagos_metodo_valido');
        DB::statement("ALTER TABLE venta_pagos ADD CONSTRAINT venta_pagos_metodo_valido CHECK (metodo IN ('efectivo', 'tarjeta', 'transferencia'))");

        DB::statement('ALTER TABLE ventas DROP CONSTRAINT IF EXISTS ventas_forma_pago_check');
        DB::statement("ALTER TABLE ventas ADD CONSTRAINT ventas_forma_pago_check CHECK (forma_pago IN ('efectivo', 'tarjeta', 'transferencia', 'mixto'))");
    }
};
