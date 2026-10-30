<?php

declare(strict_types=1);

use App\Models\Proveedor;

/*
 * Buscador de proveedores del formulario de Compras: con factura solo
 * ofrece los que tienen RTN, con recibo solo los que no; busca en la base
 * con límite y, sin texto, ofrece los últimos usados.
 */

it('con factura ofrece solo proveedores con RTN y con recibo solo los que no tienen', function () {
    Proveedor::registrar('Distribuidora Sula', '08019985012345');
    Proveedor::registrar('Pulperia Don Chepe');
    Proveedor::create(['nombre' => 'MERCADO CENTRAL', 'rtn' => '']); // RTN vacío cuenta como sin RTN

    expect(array_keys(Proveedor::buscar('', true)))->toBe(['DISTRIBUIDORA SULA'])
        ->and(Proveedor::buscar('', false))
        ->toHaveCount(2)
        ->toHaveKeys(['PULPERIA DON CHEPE', 'MERCADO CENTRAL']);

    expect(Proveedor::buscar('pulperia', true))->toBe([])
        ->and(Proveedor::buscar('distribuidora', false))->toBe([]);
});

it('busca por cualquier parte del nombre y pone primero los que empiezan así', function () {
    foreach (['LA COLONIA', 'COLONIAL SUR', 'SUPER COLONIA', 'FERRETERIA'] as $nombre) {
        Proveedor::registrar($nombre, '08019985012345');
    }

    expect(array_keys(Proveedor::buscar('colonia', true)))
        ->toBe(['COLONIAL SUR', 'LA COLONIA', 'SUPER COLONIA']);
});

it('no devuelve más del límite aunque el catálogo sea grande', function () {
    foreach (range(1, 30) as $n) {
        Proveedor::registrar(sprintf('PROVEEDOR %02d', $n), '08019985012345');
    }

    expect(Proveedor::buscar('proveedor', true))->toHaveCount(Proveedor::LIMITE_BUSQUEDA)
        ->and(Proveedor::buscar('', true))->toHaveCount(Proveedor::LIMITE_RECIENTES);
});

it('trata el % y el _ escritos como texto, no como comodines', function () {
    Proveedor::registrar('ABC 100%', '08019985012345');
    Proveedor::registrar('ABC 1000', '08019985012345');

    expect(array_keys(Proveedor::buscar('100%', true)))->toBe(['ABC 100%']);
});

it('ofrece primero el proveedor usado más recientemente', function () {
    Proveedor::registrar('PRIMERO', '08019985012345');
    $this->travel(1)->minutes();
    Proveedor::registrar('SEGUNDO', '08019985012345');
    $this->travel(1)->minutes();

    // Se vuelve a usar sin cambiar nada: igual tiene que subir en la lista.
    Proveedor::registrar('PRIMERO');

    expect(array_keys(Proveedor::recientes(true)))->toBe(['PRIMERO', 'SEGUNDO'])
        ->and(Proveedor::rtnDe('PRIMERO'))->toBe('08019985012345');
});
