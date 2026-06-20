<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('venta_id')->constrained()->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained()->restrictOnDelete();

            // SNAPSHOT al momento de la venta: si mañana cambia el producto,
            // estas líneas históricas no se alteran.
            $table->string('nombre');
            $table->decimal('precio_unitario', 12, 2);
            $table->unsignedSmallInteger('cantidad');
            $table->boolean('grava_isv');

            $table->decimal('importe', 12, 2);
            $table->timestamps();

            $table->index(['venta_id']);
            $table->index(['producto_id', 'created_at']); // producto más vendido por período
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_items');
    }
};
