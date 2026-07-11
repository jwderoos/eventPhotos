<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Event;
use App\Entity\EventNotificationStatus;
use App\Entity\EventNotificationSubscription;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class EventNotificationSubscriptionReconstituteTest extends TestCase
{
    private function event(): Event
    {
        $utc = new DateTimeZone('UTC');

        return new Event(
            'slug-x',
            'Event X',
            new DateTimeImmutable('2026-01-01 10:00:00', $utc),
            new DateTimeImmutable('2026-01-01 12:00:00', $utc),
            new User('owner@example.com', 'Owner'),
        );
    }

    public function testConfirmedIsRestoredWithTimestampsAndNoConfirmationToken(): void
    {
        $utc       = new DateTimeZone('UTC');
        $created   = new DateTimeImmutable('2026-01-02 09:00:00', $utc);
        $confirmed = new DateTimeImmutable('2026-01-02 09:05:00', $utc);

        $sub = EventNotificationSubscription::reconstituteForImport(
            $this->event(),
            'Visitor@Example.com',
            EventNotificationStatus::Confirmed,
            $created,
            $confirmed,
            null,
            null,
        );

        $this->assertSame('visitor@example.com', $sub->getEmail());
        $this->assertSame(EventNotificationStatus::Confirmed, $sub->getStatus());
        $this->assertSame($confirmed, $sub->getConfirmedAt());
        $this->assertNull($sub->getConfirmationToken());
        $this->assertNotSame('', $sub->getUnsubscribeToken());
    }

    public function testPendingKeepsAFreshConfirmationToken(): void
    {
        $created = new DateTimeImmutable('2026-01-02 09:00:00', new DateTimeZone('UTC'));

        $sub = EventNotificationSubscription::reconstituteForImport(
            $this->event(),
            'p@example.com',
            EventNotificationStatus::Pending,
            $created,
            null,
            null,
            null,
        );

        $this->assertSame(EventNotificationStatus::Pending, $sub->getStatus());
        $this->assertNotNull($sub->getConfirmationToken());
    }
}
