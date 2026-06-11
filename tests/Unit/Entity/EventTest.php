<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\EventCollection;
use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    public function testNewEventExposesRequiredFields(): void
    {
        $owner = new User('owner@example.com', 'Owner');
        $date  = new DateTimeImmutable('2026-07-15');
        $event = new Event('summer-fest', 'Summer Fest', $date, $owner);

        $this->assertSame('summer-fest', $event->getSlug());
        $this->assertSame('Summer Fest', $event->getName());
        $this->assertSame($date, $event->getDate());
        $this->assertSame($owner, $event->getOwner());
        $this->assertNotInstanceOf(EventCollection::class, $event->getCollection());
        $this->assertNull($event->getDefaultWindowMinutes());
    }

    public function testResolvedWindowMinutesFallsBackToEntityDefault(): void
    {
        $event = new Event('e', 'E', new DateTimeImmutable('2026-07-15'), new User('o@x', 'Owner'));

        $this->assertSame(Event::DEFAULT_WINDOW_MINUTES, $event->resolveWindowMinutes());
    }

    public function testResolvedWindowMinutesPrefersEventOverride(): void
    {
        $event = new Event('e', 'E', new DateTimeImmutable('2026-07-15'), new User('o@x', 'Owner'));
        $event->setDefaultWindowMinutes(15);

        $this->assertSame(15, $event->resolveWindowMinutes());
    }

    public function testDefaultWindowMinutesConstantIsPositive(): void
    {
        $this->assertGreaterThan(0, Event::DEFAULT_WINDOW_MINUTES);
    }

    public function testTimezoneDefaultsToEuropeAmsterdam(): void
    {
        $event = new Event('e', 'Event', new DateTimeImmutable('2026-06-10'), new User('o@x', 'Owner'));

        $this->assertSame('Europe/Amsterdam', $event->getTimezone());
    }

    public function testTimezoneCanBeChanged(): void
    {
        $event = new Event('e', 'Event', new DateTimeImmutable('2026-06-10'), new User('o@x', 'Owner'));
        $event->setTimezone('Europe/Amsterdam');
        $event->setTimezone('America/New_York');

        $this->assertSame('America/New_York', $event->getTimezone());
    }

    public function testSetNameDoesNotChangeSlug(): void
    {
        $event = new Event(
            'summer-fest-abc123',
            'Summer Fest',
            new DateTimeImmutable('2026-07-15'),
            new User('o@x', 'Owner'),
        );

        $event->setName('Winter Fest');

        $this->assertSame('summer-fest-abc123', $event->getSlug());
    }
}
