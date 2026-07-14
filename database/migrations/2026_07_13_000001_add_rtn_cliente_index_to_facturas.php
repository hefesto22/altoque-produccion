<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índice para el historial de compras por cliente (Clientes → Historial):
 * las consultas filtran facturas por RTN y ordenan por fecha de emisión.
 * Sin índice sería un seq scan que empeora con cada factura emitida.
 * Migración aditiva: no toca datos ni columnas existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table): void {
            $table->index(['rtn_cliente', 'emitida_at'], 'facturas_rtn_cliente_emitida_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table): void {
            $table->dropIndex('facturas_rtn_cliente_emitida_at_index');
        });
    }
};
