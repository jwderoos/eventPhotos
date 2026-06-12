<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\PhotoStatus;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;

final class PhotoTest extends TestCase
{
    public function testNewPhotoIsPending(): void
    {
        $photo = $this->makePhoto();

        $this->assertSame(PhotoStatus::Pending, $photo->getStatus());
        $this->assertNotInstanceOf(DateTimeImmutable::class, $photo->getTakenAt());
        $this->assertNull($photo->getWidth());
        $this->assertNull($photo->getHeight());
        $this->assertNull($photo->getProcessingError());
    }

    public function testMarkReadyTransitions(): void
    {
        $photo = $this->makePhoto();
        $takenAt = new DateTimeImmutable('2026-06-10 12:00:00', new DateTimeZone('UTC'));

        $photo->markReady($takenAt, 4032, 3024, 274_000);

        $this->assertSame(PhotoStatus::Ready, $photo->getStatus());
        $this->assertEquals($takenAt, $photo->getTakenAt());
        $this->assertSame(4032, $photo->getWidth());
        $this->assertSame(3024, $photo->getHeight());
        $this->assertSame(274_000, $photo->getDerivativeBytes());
    }

    public function testMarkReadyRequiresPending(): void
    {
        $photo = $this->makePhoto();
        $photo->markFailed('reason');

        $this->expectException(DomainException::class);
        $photo->markReady(new DateTimeImmutable(), 1, 1, 1);
    }

    public function testMarkFailedSetsError(): void
    {
        $photo = $this->makePhoto();
        $photo->markFailed('Missing EXIF DateTimeOriginal');

        $this->assertSame(PhotoStatus::Failed, $photo->getStatus());
        $this->assertSame('Missing EXIF DateTimeOriginal', $photo->getProcessingError());
    }

    public function testResetForRetryOnlyFromFailed(): void
    {
        $photo = $this->makePhoto();

        $this->expectException(DomainException::class);
        $photo->resetForRetry();
    }

    public function testResetForRetryClearsError(): void
    {
        $photo = $this->makePhoto();
        $photo->markFailed('boom');
        $photo->resetForRetry();

        $this->assertSame(PhotoStatus::Pending, $photo->getStatus());
        $this->assertNull($photo->getProcessingError());
    }

    private function makePhoto(): Photo
    {
        $owner = new User('owner@example.test', 'Owner');
        $event = new Event(
            'slug',
            'Event',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );

        return new Photo(
            event: $event,
            contentHash: str_repeat('a', 64),
            originalFilename: 'IMG_0001.jpg',
            byteSize: 1234567,
        );
    }
}
