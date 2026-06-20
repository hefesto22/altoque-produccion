<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Domain\ValueObjects\LineaVenta;
use App\Models\Producto;
use App\Models\Servicio;
use App\Services\Pedidos\PedidoOnlineService;
use App\Services\Pos\CotizadorVenta;
use App\Services\Pos\MenuDiaService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Página pública de pedidos en línea (sin login). El cliente ve el menú
 * del servicio activo, arma su pedido, deja sus datos y lo envía. Entra
 * como pendiente para que el personal lo confirme.
 */
#[Layout('layouts.publico')]
class PedirOnline extends Component
{
    use WithFileUploads;

    public ?int $proteinaId = null;

    /** @var array<int, int> */
    public array $complementoSel = [];

    /** @var array<int, array<string, mixed>> */
    public array $carrito = [];

    // Datos del cliente / pedido.
    public string $tipo = 'domicilio';

    public string $nombre = '';

    public string $telefono = '';

    public string $identidad = '';

    public string $direccion = '';

    public string $notas = '';

    public string $metodoPago = 'efectivo';

    /** @var mixed archivo de comprobante subido (transferencia) */
    public $comprobante = null;

    // Confirmación.
    public bool $enviado = false;

    public string $pedidoNumero = '';

    /** @var array<int, Producto> */
    public array $proteinas = [];

    /** @var array<int, Producto> */
    public array $complementos = [];

    /** @var array<int, Producto> */
    public array $bebidas = [];

    /** @var array<int, Producto> */
    public array $extras = [];

    public string $servicioNombre = '';

    public function mount(): void
    {
        $servicio = Servicio::activoAhora();
        $this->servicioNombre = $servicio?->nombre ?? 'Menú del día';

        $productos = app(MenuDiaService::class)->disponibles(now(), $servicio?->id);

        $this->proteinas = $productos->where('categoria', 'proteina')->values()->all();
        $this->complementos = $productos->where('categoria', 'complemento')->values()->all();
        $this->bebidas = $productos->where('categoria', 'bebida')->values()->all();
        $this->extras = $productos->where('categoria', 'extra')->values()->all();
    }

    public function seleccionarProteina(int $id): void
    {
        $this->proteinaId = $this->proteinaId === $id ? null : $id;
    }

    public function agregarComplemento(int $id): void
    {
        $this->complementoSel[] = $id;
    }

    public function quitarComplemento(int $id): void
    {
        $pos = array_search($id, $this->complementoSel, true);

        if ($pos !== false) {
            unset($this->complementoSel[$pos]);
            $this->complementoSel = array_values($this->complementoSel);
        }
    }

    public function contarComplemento(int $id): int
    {
        return count(array_filter($this->complementoSel, static fn (int $x): bool => $x === $id));
    }

    public function agregarPlato(): void
    {
        if ($this->proteinaId === null) {
            return;
        }

        $linea = app(CotizadorVenta::class)->cotizarPlato($this->proteinaId, $this->complementoSel);
        $this->push($linea);
        $this->proteinaId = null;
        $this->complementoSel = [];
    }

    public function agregarProducto(int $id): void
    {
        $this->push(app(CotizadorVenta::class)->cotizarProducto($id));
    }

    private function push(LineaVenta $l): void
    {
        $this->carrito[] = [
            'key'         => uniqid('p', true),
            'producto_id' => $l->productoId,
            'nombre'      => $l->nombre,
            'precio'      => $l->precioUnitario,
            'cantidad'    => $l->cantidad,
            'grava_isv'   => $l->gravaIsv,
            'detalle'     => $l->detalle,
        ];
    }

    public function quitarLinea(string $key): void
    {
        $this->carrito = array_values(array_filter($this->carrito, static fn (array $i): bool => $i['key'] !== $key));
    }

    public function getTotalProperty(): float
    {
        return round(array_sum(array_map(
            static fn (array $i): float => (float) $i['precio'] * (int) $i['cantidad'],
            $this->carrito,
        )), 2);
    }

    public function enviar(): void
    {
        $errores = [];

        if ($this->carrito === []) {
            $errores[] = 'Agregá al menos un plato.';
        }

        if (trim($this->nombre) === '' || trim($this->telefono) === '') {
            $errores[] = 'Nombre y teléfono son obligatorios.';
        }

        if ($this->tipo === 'domicilio' && trim($this->direccion) === '') {
            $errores[] = 'La dirección es obligatoria para domicilio.';
        }

        if ($this->metodoPago === 'transferencia' && $this->comprobante === null) {
            $errores[] = 'Adjuntá el comprobante de la transferencia.';
        }

        if ($errores !== []) {
            $this->addError('form', implode(' ', $errores));

            return;
        }

        $comprobantePath = null;

        if ($this->metodoPago === 'transferencia' && $this->comprobante !== null) {
            $comprobantePath = $this->comprobante->store('comprobantes', 'public');
        }

        $items = array_map(static fn (array $i): array => [
            'producto_id' => $i['producto_id'],
            'nombre'      => $i['nombre'],
            'precio'      => $i['precio'],
            'cantidad'    => $i['cantidad'],
            'grava_isv'   => $i['grava_isv'],
            'detalle'     => $i['detalle'] ?? [],
        ], $this->carrito);

        $pedido = app(PedidoOnlineService::class)->crear([
            'tipo'             => $this->tipo,
            'nombre'           => $this->nombre,
            'telefono'         => $this->telefono,
            'identidad'        => $this->identidad,
            'direccion'        => $this->direccion,
            'notas'            => $this->notas,
            'metodo_pago'      => $this->metodoPago,
            'comprobante_path' => $comprobantePath,
        ], $items, $this->total);

        $this->pedidoNumero = $pedido->numero;
        $this->enviado = true;
    }

    public function render(): View
    {
        return view('livewire.pedir-online');
    }
}
