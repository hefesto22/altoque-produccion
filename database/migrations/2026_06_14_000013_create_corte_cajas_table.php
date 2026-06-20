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
        Schema::create('corte_cajas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cajero_id')->constrained('users')->restrictOnDelete();
            $table->decimal('fondo_inicial', 12, 2)->default(0);
            $table->enum('estado', ['abierto', 'cerrado'])->default('abierto');

            $table->timestamp('abierto_at');
            $table->timestamp('cerrado_at')->nullable();

            // Snapshot al cerrar.
            $table->decimal('total_ventas', 14, 2)->default(0);
            $table->decimal('total_efectivo', 14, 2)->default(0);
            $table->decimal('total_tarjeta', 14, 2)->default(0);
            $table->decimal('total_transferencia', 14, 2)->default(0);
            $table->decimal('total_isv', 14, 2)->default(0);
            $table->unsignedInteger('cantidad_ventas')->default(0);

            $table->decimal('efectivo_contado', 12, 2)->nullable();
            $table->decimal('diferencia', 12, 2)->nullable();
            $table->string('notas')->nullable();

            $table->timestamps();

            $table->index(['cajero_id', 'estado']);
        });

        // Un solo turno ABIERTO por cajero a la vez.
        DB::statement("CREATE UNIQUE INDEX corte_cajas_abierto_unico ON corte_cajas (cajero_id) WHERE estado = 'abierto'");

        // Ahora sí, FK de ventas al corte + forma de pago.
        Schema::table('ventas', function (Blueprint $table): void {
            $table->foreign('corte_caja_id')->references('id')->on('corte_cajas')->nullOnDelete();
            $table->enum('forma_pago', ['efectivo', 'tarjeta', 'transferencia'])->default('efectivo')->after('tipo');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table): void {
            $table->dropForeign(['corte_caja_id']);
            $table->dropColumn('forma_pago');
        });

        Schema::dropIfExists('corte_cajas');
    }
};
