<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo de clientes frecuentes (los que piden factura con RTN).
        Schema::create('clientes', function (Blueprint $table): void {
            $table->id();
            $table->string('rtn', 14)->unique();
            $table->string('nombre'); // siempre en MAYÚSCULAS
            $table->timestamps();

            $table->index('nombre'); // búsqueda por nombre
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
