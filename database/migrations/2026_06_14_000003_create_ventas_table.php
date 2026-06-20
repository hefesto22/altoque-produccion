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
        // Correlativo interno de recibos: secuencia Postgres atómica,
        // sin lock ni tabla contador. Gaps en rollback son aceptables
        // (no es número fiscal). El número expuesto NO deriva del id.
        DB::statement('CREATE SEQUENCE IF NOT EXISTS recibos_correlativo_seq START 1');

        Schema::create('ventas', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('cajero_id')->constrained('users')->restrictOnDelete();
            // FK se agrega cuando exista la tabla corte_cajas (módulo de cierre).
            $table->unsignedBigInteger('corte_caja_id')->nullable()->index();

            // recibo = no fiscal; factura = SAR. Toda venta lleva su desglose.
            $table->enum('tipo', ['recibo', 'factura'])->default('recibo');

            // Correlativo interno del recibo (NO es SAR). Para control propio.
            // Único entre los recibos; null en ventas que son factura.
            $table->string('numero_recibo', 20)->nullable()->unique();

            // Datos del cliente: solo se llenan cuando pide factura.
            $table->string('rtn_cliente', 14)->nullable();
            $table->string('nombre_cliente')->nullable();

            // Desglose fiscal — SIEMPRE, emita factura o no. Dinero en numeric.
            $table->decimal('gravado', 12, 2)->default(0);
            $table->decimal('exento', 12, 2)->default(0);
            $table->decimal('isv', 12, 2)->default(0);
            $table->decimal('total', 12, 2);

            $table->timestamp('vendida_at');
            $table->timestamps();

            // Índices para las consultas reales (caja del turno y contador).
            $table->index(['cajero_id', 'vendida_at']);
            $table->index(['tipo', 'vendida_at']);
            $table->index(['vendida_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ventas');
        DB::statement('DROP SEQUENCE IF EXISTS recibos_correlativo_seq');
    }
};
