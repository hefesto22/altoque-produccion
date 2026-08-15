<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sello del negocio (el de hule) para los documentos que ve el cliente.
 *
 * Va junto al logo y al favicon porque es la misma naturaleza —una imagen de
 * identidad que se sube una vez desde Configuración— y porque el servicio que
 * arma la cotización ya lee de esta tabla.
 *
 * Migración ADITIVA: sin sello cargado la cotización se ve exactamente como
 * hasta hoy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branding_settings', function (Blueprint $table): void {
            $table->string('sello_path')->nullable()->after('favicon_path');
        });
    }

    public function down(): void
    {
        Schema::table('branding_settings', function (Blueprint $table): void {
            $table->dropColumn('sello_path');
        });
    }
};
