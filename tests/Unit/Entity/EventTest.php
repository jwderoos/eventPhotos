<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\Validation;
use App\Entity\EventCollection;
use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    public function testNewEventExposesRequiredFields(): void
    {
        $owner    = new User('owner@example.com', 'Owner');
        $startsAt = new DateTimeImmutable('2026-07-15 10:00');
        $endsAt   = new DateTimeImmutable('2026-07-15 14:00');
        $event    = new Event('summer-fest', 'Summer Fest', $startsAt, $endsAt, $owner);

        $this->assertSame('summer-fest', $event->getSlug());
        $this->assertSame('Summer Fest', $event->getName());
        $this->assertSame($startsAt, $event->getStartsAt());
        $this->assertSame($endsAt, $event->getEndsAt());
        $this->assertSame($owner, $event->getOwner());
        $this->assertNotInstanceOf(EventCollection::class, $event->getCollection());
        $this->assertNull($event->getDefaultWindowMinutes());
    }

    public function testResolvedWindowMinutesFallsBackToEntityDefault(): void
    {
        $event = new Event(
            'e',
            'E',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            new User('o@x', 'Owner'),
        );

        $this->assertSame(Event::DEFAULT_WINDOW_MINUTES, $event->resolveWindowMinutes());
    }

    public function testResolvedWindowMinutesPrefersEventOverride(): void
    {
        $event = new Event(
            'e',
            'E',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            new User('o@x', 'Owner'),
        );
        $event->setDefaultWindowMinutes(15);

        $this->assertSame(15, $event->resolveWindowMinutes());
    }

    public function testDefaultWindowMinutesConstantIsPositive(): void
    {
        $this->assertGreaterThan(0, Event::DEFAULT_WINDOW_MINUTES);
    }

    public function testTimezoneDefaultsToEuropeAmsterdam(): void
    {
        $event = new Event(
            'e',
            'Event',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            new User('o@x', 'Owner'),
        );

        $this->assertSame('Europe/Amsterdam', $event->getTimezone());
    }

    public function testTimezoneCanBeChanged(): void
    {
        $event = new Event(
            'e',
            'Event',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            new User('o@x', 'Owner'),
        );
        $event->setTimezone('Europe/Amsterdam');
        $event->setTimezone('America/New_York');

        $this->assertSame('America/New_York', $event->getTimezone());
    }

    public function testSetNameDoesNotChangeSlug(): void
    {
        $event = new Event(
            'summer-fest-abc123',
            'Summer Fest',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            new User('o@x', 'Owner'),
        );

        $event->setName('Winter Fest');

        $this->assertSame('summer-fest-abc123', $event->getSlug());
    }

    public function testValidatorRejectsEndsAtEqualToStartsAt(): void
    {
        $event = new Event(
            'e',
            'E',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            new User('o@x', 'Owner'),
        );
        $event->setStartsAt(new DateTimeImmutable('2026-07-15 10:00', new DateTimeZone('Europe/Amsterdam')));
        $event->setEndsAt(new DateTimeImmutable('2026-07-15 10:00', new DateTimeZone('Europe/Amsterdam')));

        $violations = $this->validator()->validate($event);

        $this->assertNotCount(0, $violations, 'endsAt == startsAt must be rejected');
    }

    public function testValidatorRejectsEndsAtBeforeStartsAt(): void
    {
        $event = new Event(
            'e',
            'E',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            new User('o@x', 'Owner'),
        );
        $event->setStartsAt(new DateTimeImmutable('2026-07-15 12:00', new DateTimeZone('Europe/Amsterdam')));
        $event->setEndsAt(new DateTimeImmutable('2026-07-15 11:59', new DateTimeZone('Europe/Amsterdam')));

        $violations = $this->validator()->validate($event);

        $this->assertNotCount(0, $violations);
    }

    public function testValidatorAcceptsExactlyOneMinuteWindow(): void
    {
        $event = new Event(
            'e',
            'E',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            new User('o@x', 'Owner'),
        );
        $event->setStartsAt(new DateTimeImmutable('2026-07-15 10:00', new DateTimeZone('Europe/Amsterdam')));
        $event->setEndsAt(new DateTimeImmutable('2026-07-15 10:01', new DateTimeZone('Europe/Amsterdam')));

        $violations = $this->validator()->validate($event);

        $this->assertCount(0, $violations);
    }

    public function testValidatorAcceptsExactly1440MinuteWindow(): void
    {
        $event = new Event(
            'e',
            'E',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            new User('o@x', 'Owner'),
        );
        $event->setStartsAt(new DateTimeImmutable('2026-07-15 10:00', new DateTimeZone('Europe/Amsterdam')));
        $event->setEndsAt(new DateTimeImmutable('2026-07-16 10:00', new DateTimeZone('Europe/Amsterdam')));

        $violations = $this->validator()->validate($event);

        $this->assertCount(0, $violations);
    }

    public function testValidatorRejects1441MinuteWindow(): void
    {
        $event = new Event(
            'e',
            'E',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            new User('o@x', 'Owner'),
        );
        $event->setStartsAt(new DateTimeImmutable('2026-07-15 10:00', new DateTimeZone('Europe/Amsterdam')));
        $event->setEndsAt(new DateTimeImmutable('2026-07-16 10:01', new DateTimeZone('Europe/Amsterdam')));

        $violations = $this->validator()->validate($event);

        $this->assertNotCount(0, $violations);
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }
}
