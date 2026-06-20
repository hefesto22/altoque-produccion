<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Qué combos aparecen en la pantalla del menú del día, por fecha y
        // servicio. Mismo patrón que menu_dia (productos): el conjunto de
        // filas de (fecha, servicio) es la lista de combos de ese servicio.
        Schema::create('menu_dia_combos', function (Blueprint $table): void {
            $table->id();
            $table->date('fecha');
            $table->foreignId('servicio_id')->constrained('servicios')->cascadeOnDelete();
            $table->foreignId('combo_id')->constrained('combos')->cascadeOnDelete();
            $table->timestamps();

            // Un combo no se repite en el mismo servicio/fecha.
            $table->unique(['fecha', 'servicio_id', 'combo_id']);
            // Consulta real de la pantalla: por fecha + servicio.
            $table->index(['fecha', 'servicio_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_dia_combos');
    }
};
