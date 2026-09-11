<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desglose EXONERADO en ventas y facturas (Orden de Compra Exenta, PAMEH).
 *
 * Exento y exonerado NO son lo mismo y por eso no comparten columna:
 *
 *   EXENTO     — el bien en sí no causa ISV (art. 15 Ley del ISV). Depende
 *                del producto. Ya existía.
 *   EXONERADO  — el bien sí causa ISV, pero el COMPRADOR está dispensado de
 *                pagarlo por resolución de SEFIN y lo acredita con una OCE.
 *                Depende de quién compra, no de qué compra.
 *
 * El SAR los declara por separado: la declaración mensual del ISV tiene la
 * casilla 130/230 "Ventas Exoneradas con OCE", distinta de la de ventas
 * exentas. Y el Acuerdo 481-2017 art. 11 obliga a discriminar en la factura
 * "los valores exentos, exonerados y de los gravados".
 *
 * Aditiva, con default 0: toda venta y factura existente queda exactamente
 * como está y sigue cuadrando (gravado + isv + exento + 0 = total).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table): void {
            $table->decimal('exonerado', 12, 2)->default(0)->after('exento')
                ->comment('Base amparada por Orden de Compra Exenta; su ISV no se cobra');
        });

        Schema::table('facturas', function (Blueprint $table): void {
            $table->decimal('exonerado', 12, 2)->default(0)->after('exento')
                ->comment('Base amparada por Orden de Compra Exenta; su ISV no se cobra');
        });

        // El snapshot del período declarado también lo congela: si el contador
        // vuelve a abrir un mes ya declarado tiene que ver el mismo número que
        // puso en la casilla 130.
        Schema::table('periodos_fiscales', function (Blueprint $table): void {
            $table->decimal('exonerado', 12, 2)->default(0)->after('exento')
                ->comment('Ventas exoneradas con OCE del período (casilla 130)');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table): void {
            $table->dropColumn('exonerado');
        });

        Schema::table('facturas', function (Blueprint $table): void {
            $table->dropColumn('exonerado');
        });

        Schema::table('periodos_fiscales', function (Blueprint $table): void {
            $table->dropColumn('exonerado');
        });
    }
};
