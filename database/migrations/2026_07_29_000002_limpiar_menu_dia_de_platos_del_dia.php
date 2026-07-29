<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * La primera versión del plato del día lo publicaba marcándolo en
     * `menu_dia`. Eso hacía que su fecha contara como "menú cargado" y
     * tumbaba la tolerancia del POS y la pantalla: el servicio quedaba con
     * el especial y nada más ("No hay menú cargado para ALMUERZO").
     *
     * Ahora la publicación es la columna `fecha_especial`, así que esas
     * filas sobran. Se borran solo las que apuntan a un plato del día —
     * el menú que armó el personal no se toca.
     */
    public function up(): void
    {
        DB::table('menu_dia')
            ->whereIn('producto_id', DB::table('productos')->whereNotNull('fecha_especial')->select('id'))
            ->delete();
    }

    public function down(): void
    {
        // Nada que revertir: eran filas redundantes.
    }
};
