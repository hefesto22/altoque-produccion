<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos de COMPRA EXONERADA en la factura (PAMEH / SEFIN).
 *
 * El ticket ya imprimía los tres renglones que exige el régimen de
 * facturación —orden de compra exenta, constancia de exonerado y registro
 * SAG— pero fijos en "N/A". Cuando un comprador exonerado (PMA, embajadas,
 * ONGs con convenio) llega con su Orden de Compra Exenta, ese número tiene
 * que quedar EN la factura.
 *
 * Se guardan en `facturas` y no en `ventas` porque son dato del DOCUMENTO
 * FISCAL: la reimpresión tiene que salir idéntica al original, igual que el
 * snapshot de pago y el de cliente.
 *
 * `registro_sag` NO se agrega: es para insumos agropecuarios y en un
 * restaurante no aplica — sigue impreso como N/A fijo. Si algún día hace
 * falta, es otra migración aditiva.
 *
 * Aditiva y nullable: las facturas ya emitidas quedan en NULL y siguen
 * imprimiendo N/A exactamente como hoy. No toca el desglose fiscal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table): void {
            $table->string('orden_compra_exenta', 40)->nullable()->after('nombre_cliente')
                ->comment('No. de Orden de Compra Exenta (OCE) del PAMEH');
            $table->string('constancia_exonerado', 40)->nullable()->after('orden_compra_exenta')
                ->comment('No. de constancia de registro de exonerado del comprador');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table): void {
            $table->dropColumn(['orden_compra_exenta', 'constancia_exonerado']);
        });
    }
};
