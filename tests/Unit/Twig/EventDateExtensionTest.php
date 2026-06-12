<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Entity\Event;
use App\Entity\User;
use App\Twig\EventDateExtension;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class EventDateExtensionTest extends TestCase
{
    public function testReturnsStartsAtDateInEventTimezone(): void
    {
        // 2026-07-15 22:00 UTC = 2026-07-16 00:00 Europe/Amsterdam (DST, UTC+2)
        $startsAt = new DateTimeImmutable('2026-07-15 22:00', new DateTimeZone('UTC'));
        $endsAt   = new DateTimeImmutable('2026-07-16 02:00', new DateTimeZone('UTC'));
        $event    = new Event('e', 'E', $startsAt, $endsAt, new User('o@x', 'O'));
        $event->setTimezone('Europe/Amsterdam');

        $this->assertSame('2026-07-16', new EventDateExtension()->eventDateInTz($event));
    }

    public function testReturnsCalendarDateForUtcTimezone(): void
    {
        $event = new Event(
            'e',
            'E',
            new DateTimeImmutable('2026-01-15 10:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-01-15 14:00', new DateTimeZone('UTC')),
            new User('o@x', 'O'),
        );
        $event->setTimezone('UTC');

        $this->assertSame('2026-01-15', new EventDateExtension()->eventDateInTz($event));
    }
}
