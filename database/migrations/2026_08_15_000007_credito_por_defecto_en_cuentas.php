<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las cuentas nacen CON crédito (L. 1,000 por defecto).
 *
 * Decisión con Mauricio (2026-08-15): si el saldo no alcanza, el consumo pasa
 * igual y la cuenta queda en rojo — no se reparte el pago ni se deja al
 * cliente esperando en la caja. La empresa repone cada mes, así que un
 * sobregiro chico se salda solo con el próximo depósito.
 *
 * El TOPE se queda a propósito: crédito sin límite es prestar plata sin
 * haberlo decidido. Si una empresa deja de depositar, el daño está acotado a
 * esta cifra y alguien se entera antes de que sea un problema de cobro. Se
 * sube o se baja por cuenta desde el panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuentas_prepago', function (Blueprint $table): void {
            $table->boolean('permite_credito')->default(true)->change();
            $table->decimal('limite_credito', 12, 2)->default(1000)->change();
        });
    }

    public function down(): void
    {
        Schema::table('cuentas_prepago', function (Blueprint $table): void {
            $table->boolean('permite_credito')->default(false)->change();
            $table->decimal('limite_credito', 12, 2)->default(0)->change();
        });
    }
};
