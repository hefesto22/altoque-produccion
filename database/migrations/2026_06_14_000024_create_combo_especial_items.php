<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Modo del combo especial:
        //  - 'cantidades' (default): el cliente elige (estilo buffet); la
        //    composición se describe por cantidades.
        //  - 'platillo': platillo armado con productos fijos del catálogo
        //    (ej: desayuno = pollo + embutido + huevo + frijoles fritos).
        Schema::table('productos', function (Blueprint $table): void {
            $table->string('combo_modo', 20)->default('cantidades')->after('combo_num_bebidas')
                ->comment('cantidades | platillo — solo combos especiales');
        });

        // Productos que componen un platillo armado. combo_id y producto_id
        // apuntan a productos (el combo es un producto categoría 'combo').
        Schema::create('combo_especial_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('combo_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->unsignedSmallInteger('cantidad')->default(1);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();

            $table->index(['combo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('combo_especial_items');

        Schema::table('productos', function (Blueprint $table): void {
            $table->dropColumn('combo_modo');
        });
    }
};
