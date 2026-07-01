<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Número de orden interno (ticket) por tipo, con reinicio diario:
 * LOC-1, LL-1, DOM-1… Es el número que ve cocina y el cliente para saber
 * el orden del día. NO sustituye el correlativo SAR de la factura (ese es
 * legal y va con CAI); este es control interno.
 *
 * La unicidad por (fecha, tipo) la garantiza la PK compuesta de
 * contador_tickets + el upsert atómico (ON CONFLICT) que asigna el número.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Contador diario por tipo de orden. Una fila por (día, tipo).
        Schema::create('contador_tickets', function (Blueprint $table): void {
            $table->date('fecha');
            $table->string('tipo', 20);            // local | llevar | domicilio
            $table->unsignedInteger('ultimo')->default(0);

            $table->primary(['fecha', 'tipo']);
        });

        Schema::table('ventas', function (Blueprint $table): void {
            // Tipo de orden (fulfillment). Distinto de 'tipo' (recibo/factura).
            $table->enum('tipo_orden', ['local', 'llevar', 'domicilio'])
                ->default('local')->after('tipo');
            // Número de orden interno formateado: LOC-1 / LL-1 / DOM-1.
            $table->string('numero_orden', 20)->nullable()->after('tipo_orden');

            $table->index(['tipo_orden', 'vendida_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table): void {
            $table->dropColumn(['tipo_orden', 'numero_orden']);
        });

        Schema::dropIfExists('contador_tickets');
    }
};
