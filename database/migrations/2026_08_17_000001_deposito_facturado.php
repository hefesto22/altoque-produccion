<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cambio de modelo fiscal de las cuentas prepago (2026-08-17).
 *
 * ANTES: el depósito no era venta y cada consumo emitía su factura.
 * AHORA: el DEPÓSITO es la venta —una sola factura SAR por el monto
 * depositado, 100% gravado— y el consumo ya no factura: solo descuenta.
 *
 * Lo decidió el negocio para que la empresa reciba un único documento al mes
 * en vez de una factura por almuerzo. El desglose va todo gravado a propósito:
 * al depositar todavía no se sabe qué se va a consumir, y pagar ISV de más
 * nunca lo audita el SAR — pagar de menos sí.
 *
 * ⚠️ Por eso nace `ventas.tipo = 'consumo'`: son ventas del sistema (llevan
 * items y comanda para cocina) pero NO son ventas fiscales. Su gravado, exento
 * e ISV van en cero y quedan fuera de la declaración, del libro de ventas y
 * del total de ventas del corte. Si se colaran, el mismo dinero se declararía
 * dos veces: una al depositar y otra al comer.
 *
 * `productos.es_sistema` marca el producto "Abono a cuenta de consumo", que es
 * la única línea de la factura del depósito. Se excluye del menú del POS: no
 * es comida, es un concepto contable.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ventas.tipo: se suma 'consumo' (el enum de Laravel es un CHECK).
        DB::statement('ALTER TABLE ventas DROP CONSTRAINT IF EXISTS ventas_tipo_check');
        DB::statement("ALTER TABLE ventas ADD CONSTRAINT ventas_tipo_check CHECK (tipo IN ('recibo', 'factura', 'consumo'))");

        Schema::table('productos', function (Blueprint $table): void {
            $table->boolean('es_sistema')->default(false)->after('activo')
                ->comment('Concepto interno (abono a cuenta). No se muestra en el menú del POS.');
        });

        // El producto que factura el depósito. Se crea acá y no en un seeder
        // para que exista sí o sí desde el primer depósito.
        if (! DB::table('productos')->where('es_sistema', true)->exists()) {
            DB::table('productos')->insert([
                'nombre'     => 'Abono a cuenta de consumo',
                'categoria'  => 'extra',
                'precio'     => 0,
                'grava_isv'  => true,   // el anticipo se factura gravado
                'activo'     => true,
                'es_sistema' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('productos')->where('es_sistema', true)->delete();

        Schema::table('productos', function (Blueprint $table): void {
            $table->dropColumn('es_sistema');
        });

        DB::statement('ALTER TABLE ventas DROP CONSTRAINT IF EXISTS ventas_tipo_check');
        DB::statement("ALTER TABLE ventas ADD CONSTRAINT ventas_tipo_check CHECK (tipo IN ('recibo', 'factura'))");
    }
};
