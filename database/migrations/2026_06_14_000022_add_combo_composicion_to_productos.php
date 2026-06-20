<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Composición de un combo especial (solo aplica a categoría 'combo').
        // Permite desglosar qué incluye: carne (por tipo o específica),
        // cuántos complementos y cuántas bebidas. Se muestra en la pantalla,
        // el ticket y la comanda de cocina. El precio sigue siendo fijo.
        Schema::table('productos', function (Blueprint $table): void {
            $table->string('combo_tier_carne', 20)->nullable()->after('descripcion')
                ->comment('código de tier | cualquiera — tipo de carne del combo');
            // Carne específica del combo (opcional; reemplaza al tipo).
            $table->foreignId('combo_proteina_id')->nullable()->after('combo_tier_carne')
                ->constrained('productos')->nullOnDelete();
            $table->unsignedSmallInteger('combo_num_complementos')->default(0)->after('combo_proteina_id');
            $table->unsignedSmallInteger('combo_num_bebidas')->default(0)->after('combo_num_complementos');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('combo_proteina_id');
            $table->dropColumn(['combo_tier_carne', 'combo_num_complementos', 'combo_num_bebidas']);
        });
    }
};
