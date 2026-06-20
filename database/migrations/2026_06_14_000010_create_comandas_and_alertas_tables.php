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
        // Correlativo de comanda (visible en cocina). Secuencia atómica.
        DB::statement('CREATE SEQUENCE IF NOT EXISTS comandas_correlativo_seq START 1');

        Schema::create('comandas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venta_id')->constrained()->cascadeOnDelete();
            $table->string('numero', 20)->unique();

            $table->enum('tipo', ['llevar', 'domicilio']);
            $table->enum('estado', ['pendiente', 'preparando', 'listo', 'entregado'])->default('pendiente');

            // Datos del cliente — solo para domicilio.
            $table->string('cliente_nombre')->nullable();
            $table->string('cliente_telefono')->nullable();
            $table->string('cliente_identidad')->nullable();
            $table->string('cliente_direccion')->nullable();

            $table->timestamp('listo_at')->nullable();
            $table->timestamp('entregado_at')->nullable();
            $table->timestamps();

            // La cocina consulta lo que no está entregado, por antigüedad.
            $table->index(['estado', 'created_at']);
        });

        // Alertas de reposición de complementos del buffet.
        Schema::create('alertas_reposicion', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('producto_id')->constrained()->cascadeOnDelete();
            $table->enum('estado', ['activa', 'repuesta'])->default('activa');
            $table->foreignId('creada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('repuesta_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('repuesta_at')->nullable();
            $table->timestamps();

            $table->index(['estado']);
        });

        // Una sola alerta ACTIVA por producto a la vez.
        DB::statement("CREATE UNIQUE INDEX alertas_reposicion_activa_unica ON alertas_reposicion (producto_id) WHERE estado = 'activa'");
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas_reposicion');
        Schema::dropIfExists('comandas');
        DB::statement('DROP SEQUENCE IF EXISTS comandas_correlativo_seq');
    }
};
