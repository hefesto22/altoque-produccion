<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Texto de PORCIONES INDIVIDUALES escrito a mano para la pantalla del menú.
 *
 * Lo calculado sale de los productos y sus niveles de precio, y no siempre
 * dice lo que el negocio quiere anunciar (pollo L.60 y cerdo L.70 comparten
 * nivel de combo). Con este texto se anuncia directo, sin tocar productos ni
 * niveles.
 *
 * Migración ADITIVA: mientras esté vacío, la pantalla sigue calculando la
 * línea exactamente como hasta hoy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresa_settings', function (Blueprint $table): void {
            $table->string('menu_porciones_texto')->nullable()->after('formas_pago_texto');
        });
    }

    public function down(): void
    {
        Schema::table('empresa_settings', function (Blueprint $table): void {
            $table->dropColumn('menu_porciones_texto');
        });
    }
};
