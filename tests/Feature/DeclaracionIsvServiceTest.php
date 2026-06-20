<?php

declare(strict_types=1);

use App\Domain\Exceptions\PeriodoNoFinalizadoException;
use App\Domain\Exceptions\PeriodoYaDeclaradoException;
use App\Models\User;
use App\Models\Venta;
use App\Services\Fiscal\DeclaracionIsvService;

beforeEach(function () {
    $this->service = app(DeclaracionIsvService::class);
    $this->cajero = User::factory()->create();
    // Un mes ya finalizado (el anterior al actual).
    $this->mp = now()->subMonthNoOverflow();
});

function ventaEn(int $cajeroId, string $tipo, float $gravado, float $exento, float $isv, float $total, $fecha): Venta
{
    return Venta::create([
        'cajero_id'  => $cajeroId,
        'tipo'       => $tipo,
        'gravado'    => $gravado,
        'exento'     => $exento,
        'isv'        => $isv,
        'total'      => $total,
        'vendida_at' => $fecha,
    ]);
}

it('calcula los totales del período desde las ventas (agregación SQL)', function () {
    ventaEn($this->cajero->id, 'recibo', 20, 120, 3, 143, $this->mp->copy()->startOfMonth()->addDays(2));
    ventaEn($this->cajero->id, 'factura', 40, 0, 6, 46, $this->mp->copy()->startOfMonth()->addDays(5));
    // Una venta de OTRO mes que no debe contar.
    ventaEn($this->cajero->id, 'recibo', 999, 0, 999, 999, $this->mp->copy()->subMonthNoOverflow());

    $r = $this->service->calcular($this->mp->year, $this->mp->month);

    expect($r->cantidadVentas)->toBe(2)
        ->and($r->isv)->toBe(9.0)
        ->and($r->total)->toBe(189.0)
        ->and($r->recibosTotal)->toBe(143.0)
        ->and($r->facturasTotal)->toBe(46.0);
});

it('bloquea declarar un mes que aún no termina', function () {
    expect(fn () => $this->service->declarar(now()->year, now()->month, $this->cajero->id))
        ->toThrow(PeriodoNoFinalizadoException::class);
});

it('declara el período y congela el snapshot', function () {
    ventaEn($this->cajero->id, 'factura', 40, 0, 6, 46, $this->mp->copy()->startOfMonth()->addDays(3));

    $periodo = $this->service->declarar($this->mp->year, $this->mp->month, $this->cajero->id);

    expect($periodo->estado)->toBe('declarado')
        ->and((float) $periodo->isv)->toBe(6.0)
        ->and((float) $periodo->total)->toBe(46.0)
        ->and($periodo->declarado_por)->toBe($this->cajero->id)
        ->and($periodo->declarado_at)->not->toBeNull();
});

it('no permite declarar dos veces el mismo período', function () {
    $this->service->declarar($this->mp->year, $this->mp->month, $this->cajero->id);

    expect(fn () => $this->service->declarar($this->mp->year, $this->mp->month, $this->cajero->id))
        ->toThrow(PeriodoYaDeclaradoException::class);
});

it('reabre un período declarado para rectificativa', function () {
    $this->service->declarar($this->mp->year, $this->mp->month, $this->cajero->id);

    $periodo = $this->service->reabrir($this->mp->year, $this->mp->month);

    expect($periodo->estado)->toBe('abierto')
        ->and($periodo->declarado_at)->toBeNull();
});
