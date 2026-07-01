<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saldo inicial del terminal POS (tarjeta/transferencias) al abrir turno.
 * El mismo terminal recibe tarjetas/transferencias de varios bancos y no
 * siempre se corta del todo; este monto arrastra lo que quedó pendiente.
 * Aditiva, default 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corte_cajas', function (Blueprint $table): void {
            $table->decimal('fondo_terminal', 12, 2)->default(0)->after('fondo_inicial')
                ->comment('Saldo inicial del terminal POS (tarjeta/transferencias) al abrir.');
        });
    }

    public function down(): void
    {
        Schema::table('corte_cajas', function (Blueprint $table): void {
            $table->dropColumn('fondo_terminal');
        });
    }
};
