<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;

final class EventNotificationStateTest extends TestCase
{
    public function testNewEventIsNotPublishedAndNotificationsDisabled(): void
    {
        $event = $this->makeEvent();

        $this->assertFalse($event->isPublished());
        $this->assertNotInstanceOf(DateTimeImmutable::class, $event->getPublishedAt());
        $this->assertFalse($event->areNotificationsEnabled());
    }

    public function testMarkPublishedSetsTimestamp(): void
    {
        $event = $this->makeEvent();
        $now = new DateTimeImmutable('2026-06-21 12:00:00', new DateTimeZone('UTC'));

        $event->markPublished($now);

        $this->assertTrue($event->isPublished());
        $this->assertEquals($now, $event->getPublishedAt());
    }

    public function testMarkPublishedIsOneShot(): void
    {
        $event = $this->makeEvent();
        $now = new DateTimeImmutable('2026-06-21 12:00:00', new DateTimeZone('UTC'));
        $event->markPublished($now);

        $this->expectException(DomainException::class);
        $event->markPublished($now);
    }

    public function testNotificationsToggle(): void
    {
        $event = $this->makeEvent();

        $event->enableNotifications();
        $this->assertTrue($event->areNotificationsEnabled());

        $event->disableNotifications();
        $this->assertFalse($event->areNotificationsEnabled());
    }

    private function makeEvent(): Event
    {
        return new Event(
            slug: 'sample-event',
            name: 'Sample Event',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: new User('owner@example.com', 'Owner'),
        );
    }
}
