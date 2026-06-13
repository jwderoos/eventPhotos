<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\Validation;
use App\Entity\EventCollection;
use App\Entity\Event;
use App\Entity\EventDisplayState;
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
        // Entity pins both timestamps to UTC on assignment (see Event::__construct).
        // Same instant, different DateTimeImmutable instance — compare by value.
        $this->assertEquals($startsAt, $event->getStartsAt());
        $this->assertEquals($endsAt, $event->getEndsAt());
        $this->assertSame('UTC', $event->getStartsAt()->getTimezone()->getName());
        $this->assertSame('UTC', $event->getEndsAt()->getTimezone()->getName());
        $this->assertSame($owner, $event->getOwner());
        $this->assertNotInstanceOf(EventCollection::class, $event->getCollection());
    }

    public function testPhotoMatchWindowConstantsAreAsymmetric(): void
    {
        // The "before" window must be larger than the "after" window: guests scan
        // the QR after the photo was taken, so the photo sits in the past.
        $this->assertGreaterThan(Event::WINDOW_AFTER_MINUTES, Event::WINDOW_BEFORE_MINUTES);
        $this->assertGreaterThan(0, Event::WINDOW_AFTER_MINUTES);
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

    public function testComputeDisplayStateReturnsPreBeforeStart(): void
    {
        $event = new Event(
            'e',
            'E',
            new DateTimeImmutable('2026-07-15 10:00', new DateTimeZone('Europe/Amsterdam')),
            new DateTimeImmutable('2026-07-15 14:00', new DateTimeZone('Europe/Amsterdam')),
            new User('o@x', 'Owner'),
        );

        $state = $event->computeDisplayState(
            new DateTimeImmutable('2026-07-15 09:59:59', new DateTimeZone('Europe/Amsterdam')),
        );

        $this->assertSame(EventDisplayState::Pre, $state);
    }

    public function testComputeDisplayStateReturnsLiveAtStartsAtBoundary(): void
    {
        $event = new Event(
            'e',
            'E',
            new DateTimeImmutable('2026-07-15 10:00', new DateTimeZone('Europe/Amsterdam')),
            new DateTimeImmutable('2026-07-15 14:00', new DateTimeZone('Europe/Amsterdam')),
            new User('o@x', 'Owner'),
        );

        $state = $event->computeDisplayState(
            new DateTimeImmutable('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam')),
        );

        $this->assertSame(EventDisplayState::Live, $state);
    }

    public function testComputeDisplayStateReturnsLiveInsideWindow(): void
    {
        $event = new Event(
            'e',
            'E',
            new DateTimeImmutable('2026-07-15 10:00', new DateTimeZone('Europe/Amsterdam')),
            new DateTimeImmutable('2026-07-15 14:00', new DateTimeZone('Europe/Amsterdam')),
            new User('o@x', 'Owner'),
        );

        $state = $event->computeDisplayState(
            new DateTimeImmutable('2026-07-15 12:00:00', new DateTimeZone('Europe/Amsterdam')),
        );

        $this->assertSame(EventDisplayState::Live, $state);
    }

    public function testComputeDisplayStateReturnsLiveAtEndsAtBoundary(): void
    {
        $event = new Event(
            'e',
            'E',
            new DateTimeImmutable('2026-07-15 10:00', new DateTimeZone('Europe/Amsterdam')),
            new DateTimeImmutable('2026-07-15 14:00', new DateTimeZone('Europe/Amsterdam')),
            new User('o@x', 'Owner'),
        );

        $state = $event->computeDisplayState(
            new DateTimeImmutable('2026-07-15 14:00:00', new DateTimeZone('Europe/Amsterdam')),
        );

        $this->assertSame(EventDisplayState::Live, $state);
    }

    public function testComputeDisplayStateReturnsPostAfterEnd(): void
    {
        $event = new Event(
            'e',
            'E',
            new DateTimeImmutable('2026-07-15 10:00', new DateTimeZone('Europe/Amsterdam')),
            new DateTimeImmutable('2026-07-15 14:00', new DateTimeZone('Europe/Amsterdam')),
            new User('o@x', 'Owner'),
        );

        $state = $event->computeDisplayState(
            new DateTimeImmutable('2026-07-15 14:00:01', new DateTimeZone('Europe/Amsterdam')),
        );

        $this->assertSame(EventDisplayState::Post, $state);
    }

    public function testComputeDisplayStateHandlesDstAutumnFallBack(): void
    {
        $event = new Event(
            'e',
            'E',
            new DateTimeImmutable('2026-10-25 02:30', new DateTimeZone('Europe/Amsterdam')),
            new DateTimeImmutable('2026-10-25 04:00', new DateTimeZone('Europe/Amsterdam')),
            new User('o@x', 'Owner'),
        );

        $beforeStart = new DateTimeImmutable('2026-10-25 02:29', new DateTimeZone('Europe/Amsterdam'));
        $atStart = new DateTimeImmutable('2026-10-25 02:30', new DateTimeZone('Europe/Amsterdam'));

        $this->assertSame(EventDisplayState::Pre, $event->computeDisplayState($beforeStart));
        $this->assertSame(EventDisplayState::Live, $event->computeDisplayState($atStart));
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }
}
