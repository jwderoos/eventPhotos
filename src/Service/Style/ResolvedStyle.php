<?php

declare(strict_types=1);

namespace App\Service\Style;

final readonly class ResolvedStyle
{
    public const string DEFAULT_GLOW_BASE = '#FFFFFF';

    private const float LUMINANCE_THRESHOLD = 0.5;

    private const float GLOW_ALPHA = 0.4;

    private const float SRGB_DIVISOR = 255.0;

    private const float LUMA_R = 0.2126;

    private const float LUMA_G = 0.7152;

    private const float LUMA_B = 0.0722;

    public function __construct(
        public ?string $fontColor,
        public ?string $backgroundColor,
        public ?string $buttonColor,
        public bool $glowEnabled,
    ) {
    }

    public function buttonContentColor(): ?string
    {
        if ($this->buttonColor === null) {
            return null;
        }

        return $this->relativeLuminance($this->buttonColor) > self::LUMINANCE_THRESHOLD
            ? '#000000'
            : '#FFFFFF';
    }

    public function backgroundCss(): ?string
    {
        if ($this->glowEnabled && $this->buttonColor !== null) {
            [$r, $g, $b] = $this->hexToRgb($this->buttonColor);
            $base        = $this->backgroundColor ?? self::DEFAULT_GLOW_BASE;

            return sprintf(
                'radial-gradient(circle, rgba(%d, %d, %d, %s), %s)',
                $r,
                $g,
                $b,
                self::GLOW_ALPHA,
                $base,
            );
        }

        return $this->backgroundColor;
    }

    /** @return array{int, int, int} */
    private function hexToRgb(string $hex): array
    {
        return [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];
    }

    private function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = $this->hexToRgb($hex);

        return (
            self::LUMA_R * ($r / self::SRGB_DIVISOR)
            + self::LUMA_G * ($g / self::SRGB_DIVISOR)
            + self::LUMA_B * ($b / self::SRGB_DIVISOR)
        );
    }
}
