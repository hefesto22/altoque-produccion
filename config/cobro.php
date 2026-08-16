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
     * Acuerdo del 2026-08-16, en dos tramos:
     *
     *   Año 1 (ago 2026 – jul 2027): L. 5,000 al mes. Cubre el desarrollo del
     *   sistema, el servidor y el mantenimiento, todo junto.
     *
     *   Año 2 (ago 2027 – jul 2028): L. 3,000 + 15% = L. 3,450 al mes. Ya no
     *   hay desarrollo que pagar: queda el servidor y el mantenimiento.
     *
     * Total del contrato: L. 101,400 (60,000 + 41,400).
     *
     * `monto` es SIEMPRE lo que se le cobra al cliente, con el impuesto ya
     * adentro si la etapa lo lleva. Guardar 3,000 y sumar el 15% al vuelo
     * abriría la puerta a que la pantalla y el recibo digan números distintos.
     *
     * Un módulo nuevo NO se agrega acá: se registra como cargo extra desde la
     * pantalla de Pagos (botón "Nuevo cargo"), con su mes y su concepto.
     *
     * De acá sale también el plan de la pantalla Pagos (ver
     * PagoSistemaService::sincronizarPlan). OJO: las cuotas ya creadas NO se
     * reescriben si esto cambia — lo pactado de un mes pasado es historia.
     */
    'etapas' => [
        ['meses' => 12, 'monto' => 5000.00, 'concepto' => 'Desarrollo, servidor y mantenimiento'],
        ['meses' => 12, 'monto' => 3450.00, 'concepto' => 'Servidor y mantenimiento (L. 3,000 + 15%)'],
    ],

    // Hora del día 1 a partir de la cual aparece el aviso (0-23).
    'hora_aviso' => 17,
];
