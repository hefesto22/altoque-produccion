<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->enum('categoria', ['proteina', 'complemento', 'bebida', 'extra']);

            // Dinero en numeric(12,2), nunca float.
            $table->decimal('precio', 12, 2);

            // El flag manda, NO la categoría (decisión confirmada):
            //  - bebidas: true por defecto (gravan siempre) — lo fija el seeder
            //  - resto:   false por defecto (configurable por producto)
            $table->boolean('grava_isv')->default(false)
                ->comment('Si el producto grava ISV. Default del menú: bebidas true, resto false.');

            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // El POS lista el menú activo agrupado por categoría: índice parcial.
            $table->index(['categoria', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
