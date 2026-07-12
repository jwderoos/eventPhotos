<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Event;

use App\Entity\Event;
use App\Entity\User;
use App\Service\Event\BannerUploader;
use App\Service\Image\GdImageResizer;
use DateTimeImmutable;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

final class BannerUploaderTest extends TestCase
{
    private function makeEvent(): Event
    {
        $owner = new User('owner@example.com', 'Owner');
        $owner->setPassword('x');

        return new Event(
            'uploader-slug',
            'Uploader Event',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );
    }

    public function testUploadWritesDerivativeAndStampsFields(): void
    {
        $storage = new Filesystem(new InMemoryFilesystemAdapter());
        $clock   = new MockClock('2026-07-07 12:00:00');
        $uploader = new BannerUploader(new GdImageResizer(), $storage, $clock);

        $event = $this->makeEvent();
        $bytes = (string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/photos/bigger.jpg');

        $uploader->upload($event, $bytes);

        $filename = $event->getBannerFilename();
        $this->assertNotNull($filename);
        $this->assertTrue($storage->fileExists($filename));
        $this->assertEquals($clock->now(), $event->getBannerUpdatedAt());

        // Stored file is a bounded JPEG (long edge <= 1600).
        $dims = getimagesizefromstring($storage->read($filename));
        $this->assertNotFalse($dims);
        $this->assertSame(1600, max($dims[0], $dims[1]));
    }

    public function testUploadRejectsNonImage(): void
    {
        $uploader = new BannerUploader(
            new GdImageResizer(),
            new Filesystem(new InMemoryFilesystemAdapter()),
            new MockClock('2026-07-07 12:00:00'),
        );

        $this->expectException(RuntimeException::class);
        $uploader->upload($this->makeEvent(), 'not an image');
    }

    public function testRemoveDeletesFileAndNullsFields(): void
    {
        $storage  = new Filesystem(new InMemoryFilesystemAdapter());
        $uploader = new BannerUploader(new GdImageResizer(), $storage, new MockClock('2026-07-07 12:00:00'));

        $event = $this->makeEvent();
        $uploader->upload($event, (string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/photos/bigger.jpg'));
        $filename = (string) $event->getBannerFilename();
        $this->assertTrue($storage->fileExists($filename));

        $uploader->remove($event);

        $this->assertFalse($storage->fileExists($filename));
        $this->assertNull($event->getBannerFilename());
        $this->assertNotInstanceOf(DateTimeImmutable::class, $event->getBannerUpdatedAt());
    }
}
