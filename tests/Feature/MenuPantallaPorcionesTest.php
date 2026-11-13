<?php

declare(strict_types=1);

use App\Models\EmpresaSetting;

it('la pantalla muestra el texto de porciones escrito en Datos de la Empresa', function () {
    EmpresaSetting::actual()->update(['menu_porciones_texto' => 'Res L.70, Pollo L.60, Cerdo L.70']);

    $this->get('/menu')
        ->assertOk()
        ->assertSee('PORCIONES INDIVIDUALES: RES L.70, POLLO L.60, CERDO L.70');
});

it('sin texto escrito la pantalla sigue calculando la línea como siempre', function () {
    EmpresaSetting::actual()->update(['menu_porciones_texto' => null]);

    // Sin productos en el menú no hay nada que calcular: la línea no sale.
    $this->get('/menu')
        ->assertOk()
        ->assertDontSee('PORCIONES INDIVIDUALES');
});
