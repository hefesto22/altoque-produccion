<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periodos_fiscales', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('anio');
            $table->unsignedTinyInteger('mes');

            $table->enum('estado', ['abierto', 'declarado'])->default('abierto');

            // Snapshot de totales al momento de declarar (numeric, no float).
            $table->decimal('gravado', 14, 2)->default(0);
            $table->decimal('exento', 14, 2)->default(0);
            $table->decimal('isv', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);

            // Desglose recibo vs factura del período (referencia del contador).
            $table->decimal('recibos_total', 14, 2)->default(0);
            $table->decimal('facturas_total', 14, 2)->default(0);
            $table->unsignedInteger('cantidad_ventas')->default(0);

            $table->foreignId('declarado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('declarado_at')->nullable();

            $table->timestamps();

            // Un solo registro por mes/año.
            $table->unique(['anio', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periodos_fiscales');
    }
};
