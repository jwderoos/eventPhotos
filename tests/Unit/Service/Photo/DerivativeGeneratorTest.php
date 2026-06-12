<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Photo;

use App\Service\Photo\DerivativeGenerator;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class DerivativeGeneratorTest extends TestCase
{
    public function testGeneratesThumbAndPreviewAndReportsDimensions(): void
    {
        $originalsFs = new Filesystem(new InMemoryFilesystemAdapter());
        $thumbsFs    = new Filesystem(new InMemoryFilesystemAdapter());
        $previewsFs  = new Filesystem(new InMemoryFilesystemAdapter());

        $originalBytes = (string) file_get_contents(
            dirname(__DIR__, 3) . '/fixtures/photos/bigger.jpg',
        );
        $originalsFs->write('event-1/42.jpg', $originalBytes);

        $generator = new DerivativeGenerator($originalsFs, $thumbsFs, $previewsFs);
        [$width, $height, $derivativeBytes] = $generator->generate('event-1/42.jpg');

        $this->assertSame(3000, $width);
        $this->assertSame(2000, $height);
        $this->assertTrue($thumbsFs->fileExists('event-1/42.jpg'));
        $this->assertTrue($previewsFs->fileExists('event-1/42.jpg'));
        $this->assertSame(
            $thumbsFs->fileSize('event-1/42.jpg') + $previewsFs->fileSize('event-1/42.jpg'),
            $derivativeBytes,
            'Returned derivativeBytes should equal the actual on-disk sum of thumb + preview.',
        );

        $thumbDims = getimagesizefromstring($thumbsFs->read('event-1/42.jpg'));
        $this->assertNotFalse($thumbDims);
        $this->assertSame(400, max($thumbDims[0], $thumbDims[1]));

        $previewDims = getimagesizefromstring($previewsFs->read('event-1/42.jpg'));
        $this->assertNotFalse($previewDims);
        $this->assertSame(1600, max($previewDims[0], $previewDims[1]));
    }
}
