<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correo COMERCIAL, aparte del fiscal.
 *
 * `correo` es el que va en la factura: pertenece al RTN del emisor y no se
 * toca. La cotización, en cambio, es un documento comercial y ahí el cliente
 * tiene que escribirle al correo del negocio, no al personal de la titular.
 *
 * Migración ADITIVA: mientras esté vacío, la cotización sigue mostrando el
 * correo fiscal exactamente como hasta hoy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresa_settings', function (Blueprint $table): void {
            $table->string('correo_cotizaciones')->nullable()->after('correo');
        });
    }

    public function down(): void
    {
        Schema::table('empresa_settings', function (Blueprint $table): void {
            $table->dropColumn('correo_cotizaciones');
        });
    }
};
