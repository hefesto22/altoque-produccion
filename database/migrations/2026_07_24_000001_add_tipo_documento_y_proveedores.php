<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compras: distinguir FACTURA de RECIBO de compra, y catálogo de proveedores
 * que se alimenta solo para autocompletar en la próxima compra.
 *
 * Migración ADITIVA: todo lo ya registrado queda como 'factura', que es
 * exactamente como se venía tratando. Ninguna cifra fiscal histórica cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table): void {
            // Solo la factura da crédito fiscal; el recibo se registra como
            // gasto pero no resta ISV ni entra al Libro de Compras del SAR.
            $table->string('tipo_documento', 10)->default('factura')->after('fecha');
            $table->index(['tipo_documento']);
        });

        // Un recibo puede no traer número: deja de ser obligatorio en la base.
        Schema::table('compras', function (Blueprint $table): void {
            $table->string('numero_factura', 50)->nullable()->change();
        });

        // Catálogo de proveedores (mismo patrón que `clientes`): se guarda solo
        // al registrar cada compra y sirve para sugerir nombre + RTN después.
        Schema::create('proveedores', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre')->unique(); // siempre en MAYÚSCULAS
            $table->string('rtn', 14)->nullable();
            $table->timestamps();

            $table->index('rtn');
        });

        // Siembra el catálogo con los proveedores que ya aparecen en compras,
        // así el autocompletado sirve desde el primer día.
        $existentes = DB::table('compras')
            ->selectRaw('upper(trim(proveedor_nombre)) as nombre, max(proveedor_rtn) as rtn')
            ->whereNotNull('proveedor_nombre')
            ->whereRaw("trim(proveedor_nombre) <> ''")
            ->groupByRaw('upper(trim(proveedor_nombre))')
            ->get();

        $ahora = now();

        foreach ($existentes as $fila) {
            DB::table('proveedores')->insertOrIgnore([
                'nombre'     => $fila->nombre,
                'rtn'        => $fila->rtn,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedores');

        Schema::table('compras', function (Blueprint $table): void {
            $table->dropIndex(['tipo_documento']);
            $table->dropColumn('tipo_documento');
        });
    }
};
