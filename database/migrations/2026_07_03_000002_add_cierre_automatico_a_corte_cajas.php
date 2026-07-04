<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de cierre automático: a las 11:59 PM el scheduler cierra los turnos
 * que quedaron abiertos, sin efectivo contado (nadie contó la gaveta).
 * El corte queda "por revisar" hasta que un administrador lo corrija.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corte_cajas', function (Blueprint $table): void {
            $table->boolean('cierre_automatico')->default(false)->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('corte_cajas', function (Blueprint $table): void {
            $table->dropColumn('cierre_automatico');
        });
    }
};
