<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comanda de AMPLIACIÓN: lo que el cliente pidió DESPUÉS, sobre una cuenta
 * que ya estaba abierta (otra bebida, algo que se le olvidó).
 *
 * La venta sigue siendo UNA sola (se le suman líneas mientras esté
 * pendiente de pago), pero cocina necesita un papel aparte con SOLO lo
 * nuevo — si le reimprimimos la orden completa vuelve a hacer los platos
 * que ya hizo. Este flag es lo que hace que ese ticket salga marcado y que
 * el KDS lo muestre como agregado y no como un pedido nuevo.
 *
 * Migración ADITIVA: todas las comandas existentes quedan con
 * es_ampliacion=false, que es exactamente como se venían comportando.
 * Ninguna cifra fiscal se toca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comandas', function (Blueprint $table): void {
            $table->boolean('es_ampliacion')->default(false)->after('tipo');
        });
    }

    public function down(): void
    {
        Schema::table('comandas', function (Blueprint $table): void {
            $table->dropColumn('es_ampliacion');
        });
    }
};
