<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cais', function (Blueprint $table): void {
            $table->id();

            // CAI autorizado por SAR: 32 hex en 6 bloques (ver Value Object CAI).
            $table->string('codigo', 41)->unique()
                ->comment('XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XX');

            // Datos del autoimpresor / punto de emisión y rango autorizado.
            $table->string('establecimiento', 3)->default('000');
            $table->string('punto_emision', 3)->default('000');
            $table->string('tipo_documento', 2)->default('01');

            $table->unsignedBigInteger('correlativo_desde');
            $table->unsignedBigInteger('correlativo_hasta');
            // Último correlativo CONSUMIDO. siguiente = correlativo_actual + 1.
            $table->unsignedBigInteger('correlativo_actual')->default(0);

            $table->date('fecha_autorizacion');
            // Fecha límite de emisión impresa en el documento.
            $table->date('fecha_limite_emision');

            $table->enum('estado', ['activo', 'agotado', 'vencido', 'inactivo'])
                ->default('activo');

            $table->timestamps();

            // Solo un CAI activo se busca en cada emisión; índice para el lookup.
            $table->index(['estado', 'fecha_limite_emision']);
        });

        // Invariante de rango a nivel de BD (defensa en profundidad).
        DB::statement('ALTER TABLE cais ADD CONSTRAINT cais_rango_valido CHECK (correlativo_hasta >= correlativo_desde)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cais');
    }
};
