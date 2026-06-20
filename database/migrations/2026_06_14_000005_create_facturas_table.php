<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facturas', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('venta_id')->constrained()->restrictOnDelete();
            $table->foreignId('cai_id')->constrained()->restrictOnDelete();

            // Correlativo crudo dentro del rango CAI + número formateado impreso.
            $table->unsignedBigInteger('correlativo');
            $table->string('numero', 30)->comment('000-000-01-00000001');

            // Snapshot de datos del cliente y desglose fiscal al emitir.
            $table->string('rtn_cliente', 14);
            $table->string('nombre_cliente');
            $table->decimal('gravado', 12, 2)->default(0);
            $table->decimal('exento', 12, 2)->default(0);
            $table->decimal('isv', 12, 2)->default(0);
            $table->decimal('total', 12, 2);

            // Una factura SAR NO se borra: se ANULA con motivo (no softDeletes).
            $table->boolean('anulada')->default(false);
            $table->string('motivo_anulacion')->nullable();
            $table->timestamp('anulada_at')->nullable();

            $table->timestamp('emitida_at');
            $table->timestamps();

            // Unicidad del correlativo SAR garantizada en BD (además del lock).
            $table->unique(['cai_id', 'correlativo']);
            $table->unique('numero');
            $table->index(['emitida_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturas');
    }
};
