<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La cuenta prepago apunta al CLIENTE, no repite su RTN.
 *
 * El RTN ya vive en `clientes` (es su llave única). Tenerlo también acá es
 * duplicarlo, y dos copias del mismo dato terminan discrepando: se corrige el
 * RTN en Clientes y la cuenta sigue facturando con el viejo.
 *
 * `cliente_id` es nullable a propósito: una persona sin RTN también puede
 * dejar dinero adelantado, y su consumo se factura a Consumidor Final.
 *
 * Se puede eliminar la columna `rtn` sin cuidado especial porque el módulo
 * todavía no está en producción — solo existe en pruebas y prácticamente sin
 * datos. El `down()` la devuelve por si acaso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuentas_prepago', function (Blueprint $table): void {
            $table->foreignId('cliente_id')->nullable()->after('nombre')->constrained('clientes')->nullOnDelete();
            $table->dropColumn('rtn');
        });
    }

    public function down(): void
    {
        Schema::table('cuentas_prepago', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cliente_id');
            $table->string('rtn', 14)->nullable()->after('nombre');
        });
    }
};
