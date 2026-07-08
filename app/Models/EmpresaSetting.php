<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Datos de la empresa (singleton). Una sola fila, accesible vía
 * EmpresaSetting::actual() (cacheada). Se usa en las facturas y en la
 * pantalla pública de menú. Reemplaza los valores hardcodeados de
 * config/empresa.php (que quedan solo como defaults del seed).
 *
 * @property string $razon_social
 * @property string|null $nombre_comercial
 * @property string $rtn
 * @property string|null $giro
 * @property string $direccion
 * @property string|null $telefono
 * @property string|null $telefono2
 * @property string|null $correo
 * @property string|null $sitio_web
 * @property string|null $horario
 * @property string|null $formas_pago_texto
 * @property string $factura_concepto
 * @property bool $factura_detallada
 * @property bool $comanda_en_local
 * @property int $dia_limite_anulacion
 */
class EmpresaSetting extends Model
{
    private const CACHE_KEY = 'empresa_setting:actual';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'factura_detallada'    => 'boolean',
            'comanda_en_local'     => 'boolean',
            'dia_limite_anulacion' => 'integer',
        ];
    }

    /** Singleton cacheado; lo crea con defaults de config/empresa si no existe. */
    public static function actual(): self
    {
        return Cache::rememberForever(self::CACHE_KEY, static fn (): self => self::firstOrCreate([], [
            'razon_social'         => config('empresa.nombre', 'Restaurante Al Toque'),
            'rtn'                  => config('empresa.rtn', '08011990123456'),
            'direccion'            => config('empresa.direccion', ''),
            'telefono'             => config('empresa.telefono', ''),
            'correo'               => config('empresa.correo') ?: null,
            'horario'              => config('empresa.horario'),
            'formas_pago_texto'    => config('empresa.formas_pago_texto'),
            'factura_concepto'     => config('empresa.factura_concepto', 'Alimentación'),
            'factura_detallada'    => (bool) config('empresa.factura_detallada', false),
            'dia_limite_anulacion' => (int) config('empresa.dia_limite_anulacion', 10),
        ]));
    }

    protected static function booted(): void
    {
        static::saved(static fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(static fn () => Cache::forget(self::CACHE_KEY));
    }

    /** Nombre a mostrar como emisor (comercial si existe, si no la razón social). */
    public function nombreMostrar(): string
    {
        return $this->nombre_comercial ?: $this->razon_social;
    }

    /**
     * ¿Las ventas de local imprimen comanda al cobrar? Default true (lo pidió
     * el negocio). El `?? true` cubre una instancia cacheada anterior a la
     * migración que agregó la columna.
     */
    public function imprimeComandaEnLocal(): bool
    {
        return (bool) ($this->comanda_en_local ?? true);
    }
}
