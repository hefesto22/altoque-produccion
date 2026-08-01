<?php

declare(strict_types=1);

/**
 * Cobro mensual del sistema (contrato con el desarrollador).
 *
 * El aviso le sale SOLO al gerente, el día 1 de cada mes a partir de las
 * 5:00 p.m., y se queda hasta que lo marque como recibido o hasta que
 * termine el mes. Ver App\Support\CobroMensual.
 *
 * Está en config y no en la base a propósito: es un contrato cerrado, no
 * un dato que el restaurante deba poder editar desde el panel.
 */
return [
    'titular' => 'CRUZ GARCIA MAURICIO ORLANDO / INVERSIONES OLYMPO',
    'banco'   => 'Banco de Occidente',
    'cuenta'  => '211220102994',

    // Primer mes que se cobra (YYYY-MM). Desde acá se cuentan las etapas.
    'inicio' => '2026-08',

    /*
     * Etapas del contrato, en orden. Cada una dura `meses` a `monto` por mes.
     * Al terminar la última el aviso deja de aparecer solo.
     */
    'etapas' => [
        ['meses' => 12, 'monto' => 5000.00, 'concepto' => 'Sistema'],
        ['meses' => 24, 'monto' => 3000.00, 'concepto' => 'Servidor'],
    ],

    // Hora del día 1 a partir de la cual aparece el aviso (0-23).
    'hora_aviso' => 17,
];
