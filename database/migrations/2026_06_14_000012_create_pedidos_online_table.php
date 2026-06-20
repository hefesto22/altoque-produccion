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
        DB::statement('CREATE SEQUENCE IF NOT EXISTS pedidos_online_seq START 1');

        Schema::create('pedidos_online', function (Blueprint $table): void {
            $table->id();
            $table->string('numero', 20)->unique();

            $table->enum('tipo', ['domicilio', 'retiro']);
            $table->enum('estado', ['pendiente', 'confirmado', 'rechazado'])->default('pendiente');

            $table->string('cliente_nombre');
            $table->string('cliente_telefono');
            $table->string('cliente_identidad')->nullable();
            $table->string('cliente_direccion')->nullable();
            $table->string('notas')->nullable();

            // Snapshot completo de las líneas (para reconstruir la venta al confirmar).
            $table->jsonb('items');
            $table->decimal('total', 12, 2);

            $table->enum('metodo_pago', ['efectivo', 'transferencia'])->default('efectivo');
            $table->string('comprobante_path')->nullable();

            // Se llenan al confirmar.
            $table->foreignId('venta_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('confirmado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmado_at')->nullable();
            $table->string('motivo_rechazo')->nullable();

            $table->timestamps();

            $table->index(['estado', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos_online');
        DB::statement('DROP SEQUENCE IF EXISTS pedidos_online_seq');
    }
};
