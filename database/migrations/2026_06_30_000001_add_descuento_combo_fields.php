<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desglose de descuento de combo/promoción.
 *
 * Toda venta (recibo o factura) guarda el subtotal a precio de lista y el
 * descuento otorgado, como snapshot inmutable. El ISV NO cambia: se sigue
 * calculando sobre el neto cobrado. Esto permite mostrar el descuento
 * desglosado en la factura SAR ("Descuentos y rebajas otorgados", requisito
 * del régimen de facturación) y detallarla producto por producto.
 *
 * Aditivo y retrocompatible: default 0 => ventas previas sin descuento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table): void {
            $table->decimal('subtotal_lista', 12, 2)->default(0)->after('exento')
                ->comment('Importe à la carte antes de descuento (Σ precios de lista).');
            $table->decimal('descuento', 12, 2)->default(0)->after('subtotal_lista')
                ->comment('Rebaja por combo/promoción. subtotal_lista − total.');
        });

        Schema::table('venta_items', function (Blueprint $table): void {
            // precio_lista: à la carte unitario de la línea (proteína + complementos).
            // Nullable: en líneas sueltas sin descuento es igual al precio cobrado.
            $table->decimal('precio_lista', 12, 2)->nullable()->after('precio_unitario')
                ->comment('Precio de lista (à la carte) unitario de la línea.');
            $table->decimal('descuento', 12, 2)->default(0)->after('importe')
                ->comment('Descuento de la línea (lista − cobrado).');
            // Componentes con precio de lista y flag por producto: prorrateo del
            // descuento y factura detallada. jsonb justificado: estructura variable.
            $table->jsonb('componentes')->nullable()->after('detalle')
                ->comment('Desglose [{nombre, precio, grava_isv, cantidad}] al momento de la venta.');
        });

        Schema::table('facturas', function (Blueprint $table): void {
            $table->decimal('subtotal_lista', 12, 2)->default(0)->after('exento')
                ->comment('Snapshot del subtotal à la carte al emitir.');
            $table->decimal('descuento', 12, 2)->default(0)->after('subtotal_lista')
                ->comment('Snapshot del descuento otorgado al emitir.');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table): void {
            $table->dropColumn(['subtotal_lista', 'descuento']);
        });

        Schema::table('venta_items', function (Blueprint $table): void {
            $table->dropColumn(['precio_lista', 'descuento', 'componentes']);
        });

        Schema::table('ventas', function (Blueprint $table): void {
            $table->dropColumn(['subtotal_lista', 'descuento']);
        });
    }
};
