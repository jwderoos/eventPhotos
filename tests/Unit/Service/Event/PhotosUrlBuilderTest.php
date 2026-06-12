<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Event;

use App\Entity\Event;
use App\Entity\User;
use App\Service\Event\PhotosUrlBuilder;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PhotosUrlBuilderTest extends TestCase
{
    public function testFormatsTAsHourMinuteAndPassesSlug(): void
    {
        $owner = new User('o@example.test', 'O');
        $event = new Event(
            'my-event',
            'My Event',
            new DateTimeImmutable('2026-06-12 10:00'),
            new DateTimeImmutable('2026-06-12 14:00'),
            $owner,
        );
        $event->setTimezone('Europe/Amsterdam');

        $when = new DateTimeImmutable('2026-06-12 14:35:42', new DateTimeZone('Europe/Amsterdam'));

        $generator = $this->createMock(UrlGeneratorInterface::class);
        $generator
            ->expects($this->once())
            ->method('generate')
            ->with(
                'public_event_photos',
                ['slug' => 'my-event', 't' => '14:35'],
                UrlGeneratorInterface::ABSOLUTE_PATH,
            )
            ->willReturn('/e/my-event/photos?t=14%3A35');

        $builder = new PhotosUrlBuilder($generator);

        $this->assertSame('/e/my-event/photos?t=14%3A35', $builder->build($event, $when));
    }

    public function testAbsoluteUrlFlagProducesAbsoluteUrl(): void
    {
        $owner = new User('o@example.test', 'O');
        $event = new Event(
            'my-event',
            'My Event',
            new DateTimeImmutable('2026-06-12 10:00'),
            new DateTimeImmutable('2026-06-12 14:00'),
            $owner,
        );

        $when = new DateTimeImmutable('2026-06-12 09:05:00', new DateTimeZone('UTC'));

        $generator = $this->createMock(UrlGeneratorInterface::class);
        $generator
            ->expects($this->once())
            ->method('generate')
            ->with(
                'public_event_photos',
                ['slug' => 'my-event', 't' => '09:05'],
                UrlGeneratorInterface::ABSOLUTE_URL,
            )
            ->willReturn('https://example.test/e/my-event/photos?t=09%3A05');

        $builder = new PhotosUrlBuilder($generator);

        $this->assertSame(
            'https://example.test/e/my-event/photos?t=09%3A05',
            $builder->build($event, $when, absolute: true),
        );
    }
}
