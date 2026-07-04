<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saldo final del terminal POS al cerrar el turno (pedido del restaurante):
 * fondo_terminal (saldo con que abrió) + tarjeta + transferencia del turno.
 * Con esto el que abre el turno siguiente sabe con cuánto arranca el
 * terminal, igual que el efectivo con el fondo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corte_cajas', function (Blueprint $table): void {
            $table->decimal('terminal_final', 12, 2)->nullable()->after('fondo_terminal');
        });
    }

    public function down(): void
    {
        Schema::table('corte_cajas', function (Blueprint $table): void {
            $table->dropColumn('terminal_final');
        });
    }
};
