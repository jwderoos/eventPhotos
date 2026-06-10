<?php

declare(strict_types=1);

namespace App\Service;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

final class QrCodeRenderer
{
    /**
     * SVG is vector and scales without loss at any rendered size,
     * so 320 is sufficient for the inline preview.
     */
    private const int DEFAULT_SVG_SIZE = 320;

    /**
     * PNG is raster and needs to be larger so it doesn't blur when
     * embedded in print materials or scaled up.
     */
    private const int DEFAULT_PNG_SIZE = 512;

    private const int MARGIN = 10;

    public function svg(string $url, ?int $size = null): string
    {
        $builder = new Builder(
            writer: new SvgWriter(),
            data: $url,
            size: $size ?? self::DEFAULT_SVG_SIZE,
            margin: self::MARGIN,
        );

        return $builder->build()->getString();
    }

    public function png(string $url, ?int $size = null): string
    {
        $builder = new Builder(
            writer: new PngWriter(),
            data: $url,
            size: $size ?? self::DEFAULT_PNG_SIZE,
            margin: self::MARGIN,
        );

        return $builder->build()->getString();
    }
}
