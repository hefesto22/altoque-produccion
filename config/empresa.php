<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Datos del emisor (restaurante) para documentos fiscales
|--------------------------------------------------------------------------
|
| Se imprimen en la factura SAR. Reemplazar por los datos reales del
| negocio (o moverlos a una tabla de configuración editable más adelante).
|
*/

return [
    'nombre'    => env('EMPRESA_NOMBRE', 'Restaurante Al Toque'),
    'rtn'       => env('EMPRESA_RTN', '08011990123456'),
    'direccion' => env('EMPRESA_DIRECCION', 'Barrio Mercedes, Santa Rosa de Copán'),
    'telefono'  => env('EMPRESA_TELEFONO', '9807-1926'),
    'correo'    => env('EMPRESA_CORREO', ''),

    // Día del mes SIGUIENTE hasta el cual se puede anular una factura del
    // mes anterior (cuando se declara/envía al SAR). Configurable.
    'dia_limite_anulacion' => (int) env('EMPRESA_DIA_LIMITE_ANULACION', 10),

    // Cómo se describe la venta en la FACTURA impresa:
    //  - factura_detallada = false → una sola línea con el concepto
    //    ('Alimentación'), como se acostumbra en restaurantes.
    //  - true → se imprime el detalle de cada plato/bebida.
    // El detalle completo SIEMPRE se guarda internamente, sin importar esto.
    'factura_concepto'  => env('EMPRESA_FACTURA_CONCEPTO', 'Alimentación'),
    'factura_detallada' => (bool) env('EMPRESA_FACTURA_DETALLADA', false),

    // Datos para la pantalla pública de menú (menu board).
    'horario'           => env('EMPRESA_HORARIO', 'Lunes a Sábado de 7:00 am a 8:30 pm'),
    'formas_pago_texto' => env('EMPRESA_FORMAS_PAGO', 'Aceptamos tarjetas de crédito, débito y/o transferencias electrónicas'),

    // Bancos para registrar el origen de una transferencia.
    'bancos' => [
        'Banpaís',
        'Banco de Occidente',
        'Ficohsa',
        'Banco Atlántida',
        'Cooperativa Nueva Vida',
    ],
];
