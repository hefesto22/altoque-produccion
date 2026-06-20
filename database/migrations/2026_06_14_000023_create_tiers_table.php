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
        // Catálogo de tiers (niveles de precio). Antes era un enum fijo
        // (pollo_cerdo, res); ahora se administra desde el panel para poder
        // agregar nuevas proteínas con su propio nivel de precio (pescado,
        // mariscos, etc.) sin tocar código.
        Schema::create('tiers', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 40)->unique();   // estable: lo referencian productos y combos
            $table->string('nombre', 60);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Siembra los dos tiers que ya existían como enum.
        DB::table('tiers')->insertOrIgnore([
            ['codigo' => 'pollo_cerdo', 'nombre' => 'Pollo / Cerdo', 'orden' => 1, 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
            ['codigo' => 'res', 'nombre' => 'Res', 'orden' => 2, 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Libera las columnas de tier: dejan de ser enum (check) para aceptar
        // cualquier código de tier configurado. La lógica de cobro sigue
        // comparando por código, sin cambios.
        DB::statement('ALTER TABLE productos DROP CONSTRAINT IF EXISTS productos_tier_combo_check');
        DB::statement('ALTER TABLE combos DROP CONSTRAINT IF EXISTS combos_tier_check');
    }

    public function down(): void
    {
        // Restaura los checks originales (solo los dos tiers fijos).
        DB::statement("ALTER TABLE productos ADD CONSTRAINT productos_tier_combo_check CHECK (tier_combo IS NULL OR tier_combo::text = ANY (ARRAY['pollo_cerdo','res']::text[]))");
        DB::statement("ALTER TABLE combos ADD CONSTRAINT combos_tier_check CHECK (tier::text = ANY (ARRAY['pollo_cerdo','res']::text[]))");

        Schema::dropIfExists('tiers');
    }
};
