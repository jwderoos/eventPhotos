<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\QrCodeRenderer;
use PHPUnit\Framework\TestCase;

final class QrCodeRendererTest extends TestCase
{
    public function testSvgReturnsAnSvgDocumentContainingTheUrlData(): void
    {
        $renderer = new QrCodeRenderer();

        $svg = $renderer->svg('https://example.com/e/summer-fest');

        $this->assertStringContainsString('<svg', $svg);
        $this->assertNotSame('', $svg);
    }

    public function testPngStartsWithThePngMagicBytes(): void
    {
        $renderer = new QrCodeRenderer();

        $png = $renderer->png('https://example.com/e/summer-fest');

        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
    }

    public function testDifferentUrlsProduceDifferentSvgOutput(): void
    {
        $renderer = new QrCodeRenderer();

        $a = $renderer->svg('https://example.com/e/a');
        $b = $renderer->svg('https://example.com/e/b');

        $this->assertNotSame($a, $b);
    }
}
