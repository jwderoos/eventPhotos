<?php

declare(strict_types=1);

namespace App\Service;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Endroid\QrCode\Writer\WriterInterface;
use RuntimeException;

final class QrCodeRenderer
{
    private const int DEFAULT_SVG_SIZE = 320;

    private const int DEFAULT_PNG_SIZE = 512;

    private const int MARGIN = 10;

    private const float LOGO_WIDTH_RATIO = 0.20;

    public function svg(string $url, ?string $logoContents = null, ?int $size = null): string
    {
        return $this->build(
            new SvgWriter(),
            $url,
            $logoContents,
            $size ?? self::DEFAULT_SVG_SIZE,
            supportsPunchout: false
        );
    }

    public function png(string $url, ?string $logoContents = null, ?int $size = null): string
    {
        return $this->build(
            new PngWriter(),
            $url,
            $logoContents,
            $size ?? self::DEFAULT_PNG_SIZE,
            supportsPunchout: true
        );
    }

    private function build(
        WriterInterface $writer,
        string $url,
        ?string $logoContents,
        int $size,
        bool $supportsPunchout
    ): string {
        return $this->withTempLogo(
            $logoContents,
            function (?string $logoPath) use ($writer, $url, $logoContents, $size, $supportsPunchout): string {
                $builder = new Builder(
                    writer: $writer,
                    data: $url,
                    errorCorrectionLevel: $logoContents !== null
                        ? ErrorCorrectionLevel::High
                        : ErrorCorrectionLevel::Medium,
                    size: $size,
                    margin: self::MARGIN,
                    logoPath: $logoPath ?? '',
                    logoResizeToWidth: $logoPath !== null ? (int)($size * self::LOGO_WIDTH_RATIO) : null,
                    logoPunchoutBackground: $logoPath !== null && $supportsPunchout,
                );

                return $builder->build()->getString();
            },
        );
    }

    /**
     * @param callable(?string): string $fn
     */
    private function withTempLogo(?string $logoContents, callable $fn): string
    {
        if ($logoContents === null) {
            return $fn(null);
        }

        $path = tempnam(sys_get_temp_dir(), 'qrlogo_');
        if ($path === false) {
            throw new RuntimeException('Failed to create temp file for QR logo.');
        }

        try {
            file_put_contents($path, $logoContents);

            return $fn($path);
        } finally {
            @unlink($path);
        }
    }
}
