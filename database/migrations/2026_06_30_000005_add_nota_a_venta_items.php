<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nota por línea de venta ("sin cebolla", "bien cocido"): indicación del
 * cliente que la cocina debe respetar. Snapshot con la venta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_items', function (Blueprint $table): void {
            $table->string('nota')->nullable()->after('detalle');
        });
    }

    public function down(): void
    {
        Schema::table('venta_items', function (Blueprint $table): void {
            $table->dropColumn('nota');
        });
    }
};
