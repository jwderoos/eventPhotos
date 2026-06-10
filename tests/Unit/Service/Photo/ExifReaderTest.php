<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Photo;

use App\Service\Photo\ExifReader;
use App\Service\Photo\PhotoRejected;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ExifReaderTest extends TestCase
{
    private string $fixturesDir;

    private ExifReader $reader;

    protected function setUp(): void
    {
        $this->fixturesDir = dirname(__DIR__, 3) . '/fixtures/photos';
        $this->reader = new ExifReader();
    }

    public function testReadsDateTimeOriginalInEventTimezoneAndReturnsUtc(): void
    {
        $taken = $this->reader->readTakenAt(
            $this->fixturesDir . '/with-datetime-original.jpg',
            new DateTimeZone('Europe/Amsterdam'),
        );

        $expected = new DateTimeImmutable('2026-06-10 10:34:56', new DateTimeZone('UTC'));
        $this->assertEquals($expected, $taken);
    }

    public function testPrefersOffsetTimeOriginal(): void
    {
        $taken = $this->reader->readTakenAt(
            $this->fixturesDir . '/with-offset-time.jpg',
            new DateTimeZone('America/Los_Angeles'),
        );

        // Even though we passed LA, the +02:00 in EXIF should be used.
        $expected = new DateTimeImmutable('2026-06-10 10:34:56', new DateTimeZone('UTC'));
        $this->assertEquals($expected, $taken);
    }

    public function testThrowsWhenDateTimeOriginalMissing(): void
    {
        $this->expectException(PhotoRejected::class);
        $this->expectExceptionMessageMatches('/DateTimeOriginal/');

        $this->reader->readTakenAt(
            $this->fixturesDir . '/no-exif.jpg',
            new DateTimeZone('UTC'),
        );
    }
}
