<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Servicios del día (desayuno / almuerzo / cena) con su ventana horaria.
        Schema::create('servicios', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->string('slug')->unique();
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Menú del día: qué productos del catálogo van en cada servicio de
        // una fecha. Si una fecha no tiene filas, el POS muestra todo el menú.
        Schema::create('menu_dia', function (Blueprint $table): void {
            $table->id();
            $table->date('fecha');
            $table->foreignId('servicio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['fecha', 'servicio_id', 'producto_id']);
            $table->index(['fecha', 'servicio_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_dia');
        Schema::dropIfExists('servicios');
    }
};
