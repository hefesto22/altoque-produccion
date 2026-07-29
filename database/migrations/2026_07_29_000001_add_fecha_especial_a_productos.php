<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Plato especial del día: un platillo completo que solo se ofrece en
        // UNA fecha. Se modela sobre productos (categoría 'combo', modo
        // 'platillo') para reusar el motor de cobro, el snapshot de
        // venta_items y la comanda de cocina sin tocar la persistencia
        // fiscal — un plato vendido JAMÁS se borra, solo deja de aparecer.
        //
        // Aditiva y nullable: todo el catálogo permanente queda en NULL y su
        // comportamiento no cambia.
        Schema::table('productos', function (Blueprint $table): void {
            $table->date('fecha_especial')->nullable()
                ->comment('Solo platos del día: única fecha en que se ofrece. NULL = catálogo permanente.');
        });

        // Las dos consultas reales: "platos del día de esta fecha" (pantalla
        // Menú del Día) y "excluir los de otras fechas" (POS y pantalla TV).
        Schema::table('productos', function (Blueprint $table): void {
            $table->index(['fecha_especial'], 'productos_fecha_especial_index');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table): void {
            $table->dropIndex('productos_fecha_especial_index');
            $table->dropColumn('fecha_especial');
        });
    }
};
