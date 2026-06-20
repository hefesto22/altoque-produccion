<?php

declare(strict_types=1);

namespace App\Services\Facturacion;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Genera códigos QR como SVG (sin GD ni Imagick), aptos para embeber en
 * el PDF de la factura. El QR apunta a la URL pública de verificación.
 */
final class QrService
{
    public function svg(string $texto, int $tamano = 150): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($tamano, 1),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString($texto);
    }

    /** SVG como data URI, listo para usar en <img src="..."> dentro del PDF. */
    public function dataUri(string $texto, int $tamano = 150): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->svg($texto, $tamano));
    }
}
