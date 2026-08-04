<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cola de impresión del local.
 *
 * Hay UNA sola impresora térmica, conectada a la computadora de la caja, pero
 * los pedidos entran también desde tablets. Cada documento que tiene que salir
 * en papel deja una fila acá; la computadora la reclama y la imprime. Como no
 * hay pantalla en cocina, el ticket impreso es el único canal hacia la cocina:
 * nada se descarta, si nadie lo imprime queda 'pendiente' y visible.
 *
 * Migración ADITIVA: no toca ninguna tabla existente. Con la tabla vacía el
 * sistema imprime exactamente como venía imprimiendo.
 *
 * `tipo` y `estado` van como string + CHECK con NOMBRE EXPLÍCITO en vez de
 * $table->enum(): en Postgres el enum de Laravel es un CHECK con nombre
 * autogenerado, y ampliarlo obliga a un DROP CONSTRAINT a ciegas (ya pasó en
 * 2026_07_02_000001_add_local_a_tipo_comanda.php). Acá nace con nombre propio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impresiones', function (Blueprint $table): void {
            $table->id();

            // A qué documento apunta. 'documentos' NO es un documento propio:
            // es factura + comanda en un solo HTML, y su referencia_id es el
            // id de la FACTURA.
            $table->string('tipo', 20);
            $table->unsignedBigInteger('referencia_id');

            // Snapshot para la pantalla de la caja: se congela al encolar, así
            // la cola se lee igual aunque después cambie la venta.
            $table->string('etiqueta');
            $table->string('detalle')->nullable();

            $table->string('estado', 12)->default('pendiente');

            $table->foreignId('solicitado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('impreso_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('impreso_at')->nullable();
            $table->timestamps();

            // referencia_id es una FK polimórfica (sin constraint): se indexa a mano.
            $table->index(['tipo', 'referencia_id']);
        });

        DB::statement("ALTER TABLE impresiones ADD CONSTRAINT impresiones_tipo_check CHECK (tipo IN ('comanda', 'factura', 'documentos', 'corte'))");
        DB::statement("ALTER TABLE impresiones ADD CONSTRAINT impresiones_estado_check CHECK (estado IN ('pendiente', 'impreso', 'cancelado'))");

        // La cola solo consulta pendientes: índice parcial que se mantiene en
        // un puñado de tuplas aunque la tabla llegue al medio millón de filas.
        DB::statement("CREATE INDEX impresiones_pendientes_idx ON impresiones (created_at) WHERE estado = 'pendiente'");

        // "Impresos recientes" (reimprimir) ordena por impreso_at descendente.
        DB::statement("CREATE INDEX impresiones_recientes_idx ON impresiones (impreso_at DESC) WHERE estado = 'impreso'");
    }

    public function down(): void
    {
        Schema::dropIfExists('impresiones');
    }
};
