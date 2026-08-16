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
     *
     * Acuerdo del 2026-08-15: UNA sola cuota de L. 5,000 al mes durante 24
     * meses — L. 120,000 en total. Esa cuota cubre todo junto: el desarrollo
     * del sistema, el servidor y el mantenimiento. No se desglosa ni se
     * cobra nada aparte.
     *
     * Los montos van SIN impuestos: el ISV de este contrato lo declara el
     * desarrollador por su cuenta, a mano.
     *
     * De acá sale también el plan de la pantalla Pagos (ver
     * PagoSistemaService::sincronizarPlan). OJO: las cuotas ya creadas NO se
     * reescriben si esto cambia — lo pactado de un mes pasado es historia.
     */
    'etapas' => [
        ['meses' => 24, 'monto' => 5000.00, 'concepto' => 'Desarrollo, servidor y mantenimiento'],
    ],

    // Hora del día 1 a partir de la cual aparece el aviso (0-23).
    'hora_aviso' => 17,
];
