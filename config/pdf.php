<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Rutas de Browsershot (Chromium headless para PDFs)
|--------------------------------------------------------------------------
|
| Si node o chrome no están en el PATH del sistema, definir las rutas
| en .env (BROWSERSHOT_NODE_PATH / BROWSERSHOT_CHROME_PATH). Vacío =
| Browsershot intenta autodetectar.
|
*/

return [
    'node_path'   => env('BROWSERSHOT_NODE_PATH') ?: null,
    'npm_path'    => env('BROWSERSHOT_NPM_PATH') ?: null,
    'chrome_path' => env('BROWSERSHOT_CHROME_PATH') ?: null,
];
