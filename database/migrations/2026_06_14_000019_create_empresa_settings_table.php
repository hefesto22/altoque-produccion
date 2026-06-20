<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Datos de la empresa (singleton): aparecen en facturas y en la
        // pantalla pública de menú. Editable desde el panel.
        Schema::create('empresa_settings', function (Blueprint $table): void {
            $table->id();

            // Datos legales / fiscales.
            $table->string('razon_social')->default('Restaurante Al Toque');
            $table->string('nombre_comercial')->nullable();
            $table->string('rtn', 14)->default('08011990123456');
            $table->string('giro')->nullable();

            // Ubicación / contacto.
            $table->string('direccion')->default('Barrio Mercedes, Santa Rosa de Copán');
            $table->string('telefono')->nullable();
            $table->string('telefono2')->nullable();
            $table->string('correo')->nullable();
            $table->string('sitio_web')->nullable();

            // Pantalla de menú.
            $table->string('horario')->nullable();
            $table->string('formas_pago_texto')->nullable();

            // Facturación.
            $table->string('factura_concepto')->default('Alimentación');
            $table->boolean('factura_detallada')->default(false);
            $table->unsignedSmallInteger('dia_limite_anulacion')->default(10);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_settings');
    }
};
