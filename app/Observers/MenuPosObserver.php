<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Producto;
use App\Services\Pos\MenuDiaService;

/**
 * Tira la caché del menú del POS cuando alguien toca el catálogo: precio,
 * nombre, activo/inactivo o el plato del día. Sin esto, cambiar un precio
 * no se vería en la caja hasta el otro día.
 *
 * Se registra sobre Producto Y ComboEspecial (AppServiceProvider): son la
 * misma tabla, pero Eloquent dispara los eventos con la clase concreta, así
 * que un observer sobre el padre no se entera de lo que hace el hijo.
 */
final class MenuPosObserver
{
    public function saved(Producto $producto): void
    {
        MenuDiaService::olvidarCachePos();
    }

    public function deleted(Producto $producto): void
    {
        MenuDiaService::olvidarCachePos();
    }
}
