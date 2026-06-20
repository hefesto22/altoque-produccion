<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nueva categoría 'combo': combos promocionales con nombre y precio
        // fijo. Se modelan como producto para reusar todo el motor de cobro
        // (snapshot, FK fiscal, menú del día) sin tocar la persistencia.
        DB::statement('ALTER TABLE productos DROP CONSTRAINT IF EXISTS productos_categoria_check');
        DB::statement("ALTER TABLE productos ADD CONSTRAINT productos_categoria_check CHECK (categoria::text = ANY (ARRAY['proteina','complemento','bebida','extra','combo']::text[]))");

        // Texto opcional "qué incluye" el combo, para mostrar en la pantalla.
        Schema::table('productos', function (Blueprint $table): void {
            $table->text('descripcion')->nullable()->after('tier_combo');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table): void {
            $table->dropColumn('descripcion');
        });

        // Limpia cualquier producto 'combo' antes de restringir el check.
        DB::table('productos')->where('categoria', 'combo')->delete();

        DB::statement('ALTER TABLE productos DROP CONSTRAINT IF EXISTS productos_categoria_check');
        DB::statement("ALTER TABLE productos ADD CONSTRAINT productos_categoria_check CHECK (categoria::text = ANY (ARRAY['proteina','complemento','bebida','extra']::text[]))");
    }
};
