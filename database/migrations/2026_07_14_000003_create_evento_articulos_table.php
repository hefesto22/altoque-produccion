<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo propio de EVENTOS: artículos con precios personalizados
 * (panas, cazuelas, platillos por evento…), separado del catálogo del
 * menú del restaurante. Se alimenta solo: cada ítem cotizado se
 * registra aquí con su último precio (ver EventoArticulo::registrar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evento_articulos', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre')->unique();
            $table->decimal('precio', 12, 2)->default(0);
            $table->boolean('grava_isv')->default(true);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_articulos');
    }
};
