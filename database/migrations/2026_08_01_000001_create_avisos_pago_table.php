<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un registro por mes marcado como "recordatorio recibido" por el gerente.
     *
     * Es aditiva y no toca nada existente: si la tabla está vacía, el aviso
     * simplemente se muestra. Se guarda quién y cuándo lo marcó para poder
     * mirar el historial después.
     */
    public function up(): void
    {
        Schema::create('avisos_pago', function (Blueprint $table): void {
            $table->id();
            // Primer día del mes cobrado. Único: un mes se marca una sola vez.
            $table->date('periodo')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('confirmado_en');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avisos_pago');
    }
};
