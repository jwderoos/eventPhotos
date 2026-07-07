<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Image;

use App\Service\Image\GdImageResizer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GdImageResizerTest extends TestCase
{
    public function testDecodeScaleEncodeRoundTripBoundsLongEdgeAndPreservesAspect(): void
    {
        $resizer = new GdImageResizer();
        $bytes   = (string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/photos/bigger.jpg');

        $image = $resizer->decode($bytes);
        $this->assertSame(3000, imagesx($image));
        $this->assertSame(2000, imagesy($image));

        $scaled = $resizer->scaleTo($image, imagesx($image), imagesy($image), 1600);
        $this->assertSame(1600, max(imagesx($scaled), imagesy($scaled)));
        // 3000x2000 -> 1600x1067 (aspect preserved)
        $this->assertSame(1067, min(imagesx($scaled), imagesy($scaled)));

        $jpeg = $resizer->encode($scaled, 85);
        $dims = getimagesizefromstring($jpeg);
        $this->assertNotFalse($dims);
        $this->assertSame(1600, max($dims[0], $dims[1]));
    }

    public function testDecodeThrowsOnGarbage(): void
    {
        $this->expectException(RuntimeException::class);
        new GdImageResizer()->decode('not an image');
    }
}
