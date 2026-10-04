<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Renders text (an `otpauth://` URI) as an SVG QR code on the server. Nothing leaves our infrastructure.
 */
final class QrCode
{
    public function svg(string $content, int $size = 224): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd()));

        return $writer->writeString($content);
    }
}
