<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo de Eventos: cotizaciones con precios personalizados.
 *
 * Una cotización NO es documento fiscal (sin correlativo SAR): si el
 * evento se concreta, la factura sale después por el flujo normal del
 * POS. Los totales se guardan desglosados (gravado/exento/ISV) con el
 * mismo criterio de "ISV incluido en el precio" del resto del sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotizaciones', function (Blueprint $table): void {
            $table->id();
            $table->string('cliente_nombre');
            $table->string('cliente_telefono', 30)->nullable();
            $table->string('cliente_rtn', 14)->nullable();
            $table->date('evento_fecha')->nullable();
            $table->string('evento_lugar')->nullable();
            $table->unsignedSmallInteger('personas')->nullable();
            $table->string('estado', 15)->default('borrador'); // borrador|enviada|aceptada|rechazada
            $table->unsignedSmallInteger('validez_dias')->default(15);
            $table->decimal('descuento', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('gravado', 12, 2)->default(0);
            $table->decimal('exento', 12, 2)->default(0);
            $table->decimal('isv', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('anticipo', 12, 2)->nullable();
            $table->text('notas')->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['estado', 'evento_fecha']);
        });

        Schema::create('cotizacion_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cotizacion_id')->constrained('cotizaciones')->cascadeOnDelete();
            $table->string('descripcion');
            $table->decimal('cantidad', 10, 2)->default(1);
            $table->decimal('precio_unitario', 12, 2)->default(0);
            $table->boolean('grava_isv')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotizacion_items');
        Schema::dropIfExists('cotizaciones');
    }
};
