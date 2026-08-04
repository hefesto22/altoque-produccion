<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anulación de un pedido PENDIENTE de pago.
 *
 * Hasta ahora no había forma de deshacer un pedido que nunca se cobró: si el
 * cliente se iba o el mesero se equivocaba, esa venta se quedaba para siempre
 * en "Pedidos por cobrar". Ahora se anula, y anulada no se cobra más: si el
 * pedido no se hizo, no hay nada que cobrar.
 *
 * Se MARCA, no se borra: queda quién y cuándo para poder revisarlo después.
 * Mismo criterio (y mismos nombres de columna) que la anulación de facturas.
 *
 * Migración ADITIVA sobre `ventas`: todo lo existente queda con anulada=false,
 * que es exactamente como se venía comportando. Ninguna cifra fiscal cambia —
 * un pendiente nunca tuvo correlativo SAR ni entró a un corte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table): void {
            $table->boolean('anulada')->default(false)->after('pagada');
            $table->timestamp('anulada_at')->nullable()->after('anulada');
            $table->foreignId('anulada_por')->nullable()->after('anulada_at')->constrained('users')->nullOnDelete();
            $table->string('motivo_anulacion')->nullable()->after('anulada_por');
        });

        // El POS relee los pendientes cada 15s: índice parcial para que esa
        // consulta no crezca con el histórico de ventas cobradas.
        DB::statement('CREATE INDEX ventas_pendientes_idx ON ventas (vendida_at) WHERE pagada = false AND anulada = false');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ventas_pendientes_idx');

        Schema::table('ventas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('anulada_por');
            $table->dropColumn(['anulada', 'anulada_at', 'motivo_anulacion']);
        });
    }
};
