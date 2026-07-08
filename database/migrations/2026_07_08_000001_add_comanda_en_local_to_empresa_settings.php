<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Comanda en ventas de local: el negocio pidió que al cobrar en el local
 * también se imprima la comanda (sale junto a la factura en la misma
 * impresión). Configurable en Datos de la Empresa; default activado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresa_settings', function (Blueprint $table): void {
            $table->boolean('comanda_en_local')->default(true);
        });

        // El singleton de EmpresaSetting vive cacheado como objeto serializado:
        // sin este forget, el modelo en cache no trae la columna nueva y el
        // flag se leería null hasta que alguien guarde Datos de la Empresa.
        Cache::forget('empresa_setting:actual');
    }

    public function down(): void
    {
        Schema::table('empresa_settings', function (Blueprint $table): void {
            $table->dropColumn('comanda_en_local');
        });

        Cache::forget('empresa_setting:actual');
    }
};
