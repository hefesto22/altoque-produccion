<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pagos del sistema: lo que el restaurante le paga al desarrollador.
 *
 * NO es una venta del negocio. No consume CAI, no lleva ISV y no entra a
 * ningún corte de caja ni a los libros fiscales — es plata que SALE del
 * restaurante, no que entra. Los impuestos de este contrato los declara el
 * desarrollador por su cuenta, así que acá los montos van limpios.
 *
 * Una fila por MES del contrato: el plan se genera desde `config/cobro.php`
 * (que es donde vive el acuerdo) y cada fila se marca pagada cuando el
 * dinero llega. Guardar el plan en la base y no calcularlo al vuelo es a
 * propósito: el monto pactado de un mes ya emitido no puede cambiar porque
 * alguien editó la config después.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos_sistema', function (Blueprint $table): void {
            $table->id();
            // Primer día del mes cobrado. Único: un mes se cobra una sola vez.
            $table->date('periodo')->unique();
            $table->unsignedSmallInteger('numero');        // 1..24, para leerlo como "cuota 7 de 24"
            $table->decimal('monto', 12, 2);               // lo pactado ESE mes
            $table->string('concepto', 60);                // "Servidor y sistema"
            $table->boolean('pagada')->default(false);
            $table->timestamp('pagada_at')->nullable();
            // Lo que realmente entró: casi siempre igual al pactado, pero un
            // abono parcial o un redondeo no puede quedar sin registro.
            $table->decimal('monto_pagado', 12, 2)->nullable();
            $table->string('forma_pago', 20)->nullable();  // efectivo | transferencia | cheque
            $table->string('banco', 60)->nullable();
            $table->string('referencia', 60)->nullable();
            $table->text('notas')->nullable();
            // Quién lo marcó. Solo el super_admin puede, y queda por escrito.
            $table->foreignId('marcada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['pagada', 'periodo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_sistema');
    }
};
