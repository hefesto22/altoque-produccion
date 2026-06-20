<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Snapshot de los items que van a cocina. Permite pedidos mixtos:
        // la comanda lleva solo las líneas marcadas "para llevar", no toda
        // la venta. jsonb justificado: lista variable por comanda.
        Schema::table('comandas', function (Blueprint $table): void {
            $table->jsonb('items')->nullable()->after('estado')
                ->comment('Snapshot de los platos que van a cocina (subconjunto de la venta).');
        });
    }

    public function down(): void
    {
        Schema::table('comandas', function (Blueprint $table): void {
            $table->dropColumn('items');
        });
    }
};
