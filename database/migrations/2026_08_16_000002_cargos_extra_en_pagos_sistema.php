<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cargos extra: lo que se cobra FUERA de la mensualidad.
 *
 * Si el restaurante pide un módulo nuevo, ese trabajo se cobra aparte y no
 * puede deformar el plan del contrato. Por eso vive en la misma tabla (es el
 * mismo estado de cuenta) pero marcado con `es_extra`, sin número de cuota y
 * con su propio concepto.
 *
 * Se cae el único de `periodo`: un mes puede tener su mensualidad Y un cargo
 * extra. El único pasa a `numero`, que ahora identifica la cuota del plan
 * (nulo en los extras; Postgres admite varios nulos en un índice único).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagos_sistema', function (Blueprint $table): void {
            $table->boolean('es_extra')->default(false)->after('concepto');
            $table->unsignedSmallInteger('numero')->nullable()->change();
            $table->dropUnique(['periodo']);
            $table->unique('numero');
            $table->index(['es_extra', 'periodo']);
        });
    }

    public function down(): void
    {
        Schema::table('pagos_sistema', function (Blueprint $table): void {
            $table->dropIndex(['es_extra', 'periodo']);
            $table->dropUnique(['numero']);
            $table->unique('periodo');
            $table->dropColumn('es_extra');
        });
    }
};
