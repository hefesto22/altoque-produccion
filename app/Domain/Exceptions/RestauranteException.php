<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Excepción raíz del dominio del restaurante (POS).
 *
 * Toda excepción de negocio del POS — fiscal, venta, caja — hereda de
 * aquí, que a su vez hereda del tronco del grupo. Nunca se usan
 * excepciones genéricas de PHP para errores de dominio.
 */
abstract class RestauranteException extends GrupoOlympoException {}
