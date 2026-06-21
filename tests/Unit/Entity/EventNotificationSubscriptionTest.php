<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Event;
use App\Entity\EventNotificationStatus;
use App\Entity\EventNotificationSubscription;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;

final class EventNotificationSubscriptionTest extends TestCase
{
    private const string TZ = 'UTC';

    public function testConstructionLowercasesEmailAndStartsPending(): void
    {
        $sub = $this->make('Visitor@Example.COM', $this->at('2026-06-21 10:00:00'));

        $this->assertSame('visitor@example.com', $sub->getEmail());
        $this->assertSame(EventNotificationStatus::Pending, $sub->getStatus());
        $this->assertNotNull($sub->getConfirmationToken());
        $this->assertNotSame('', $sub->getUnsubscribeToken());
        $this->assertNotInstanceOf(DateTimeImmutable::class, $sub->getNotifiedAt());
    }

    public function testTokensAreUrlSafeAndDistinct(): void
    {
        $sub = $this->make('a@example.com', $this->at('2026-06-21 10:00:00'));

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', (string) $sub->getConfirmationToken());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $sub->getUnsubscribeToken());
        $this->assertNotSame($sub->getConfirmationToken(), $sub->getUnsubscribeToken());
        $this->assertGreaterThanOrEqual(43, strlen((string) $sub->getConfirmationToken()));
    }

    public function testConfirmFromPendingClearsToken(): void
    {
        $sub = $this->make('a@example.com', $this->at('2026-06-21 10:00:00'));

        $sub->confirm($this->at('2026-06-22 10:00:00'));

        $this->assertSame(EventNotificationStatus::Confirmed, $sub->getStatus());
        $this->assertNull($sub->getConfirmationToken());
        $this->assertFalse($sub->isConfirmationExpired($this->at('2026-07-01 10:00:00')));
    }

    public function testConfirmRejectedWhenExpired(): void
    {
        $sub = $this->make('a@example.com', $this->at('2026-06-21 10:00:00'));

        $this->assertTrue($sub->isConfirmationExpired($this->at('2026-06-28 10:00:01')));

        $this->expectException(DomainException::class);
        $sub->confirm($this->at('2026-06-28 10:00:01'));
    }

    public function testExpiryBoundaryIsSevenDays(): void
    {
        $sub = $this->make('a@example.com', $this->at('2026-06-21 10:00:00'));

        $this->assertFalse($sub->isConfirmationExpired($this->at('2026-06-28 10:00:00')));
        $this->assertTrue($sub->isConfirmationExpired($this->at('2026-06-28 10:00:01')));
    }

    public function testConfirmRejectedWhenNotPending(): void
    {
        $sub = $this->make('a@example.com', $this->at('2026-06-21 10:00:00'));
        $sub->confirm($this->at('2026-06-22 10:00:00'));

        $this->expectException(DomainException::class);
        $sub->confirm($this->at('2026-06-22 11:00:00'));
    }

    public function testUnsubscribeFromConfirmed(): void
    {
        $sub = $this->make('a@example.com', $this->at('2026-06-21 10:00:00'));
        $sub->confirm($this->at('2026-06-22 10:00:00'));

        $sub->unsubscribe($this->at('2026-06-23 10:00:00'));

        $this->assertSame(EventNotificationStatus::Unsubscribed, $sub->getStatus());
    }

    public function testRestartPendingFromUnsubscribedIssuesFreshToken(): void
    {
        $sub = $this->make('a@example.com', $this->at('2026-06-21 10:00:00'));
        $firstToken = $sub->getConfirmationToken();
        $sub->confirm($this->at('2026-06-22 10:00:00'));
        $sub->unsubscribe($this->at('2026-06-23 10:00:00'));

        $sub->restartPending($this->at('2026-06-24 10:00:00'));

        $this->assertSame(EventNotificationStatus::Pending, $sub->getStatus());
        $this->assertNotNull($sub->getConfirmationToken());
        $this->assertNotSame($firstToken, $sub->getConfirmationToken());
        $this->assertFalse($sub->isConfirmationExpired($this->at('2026-06-30 10:00:00')));
    }

    public function testMarkNotifiedOnlyFromConfirmed(): void
    {
        $sub = $this->make('a@example.com', $this->at('2026-06-21 10:00:00'));

        $this->expectException(DomainException::class);
        $sub->markNotified($this->at('2026-06-22 10:00:00'));
    }

    private function make(string $email, DateTimeImmutable $now): EventNotificationSubscription
    {
        $event = new Event(
            slug: 'sample-event',
            name: 'Sample Event',
            startsAt: $this->at('2026-01-01 10:00:00'),
            endsAt: $this->at('2026-01-01 18:00:00'),
            owner: new User('owner@example.com', 'Owner'),
        );

        return new EventNotificationSubscription($event, $email, $now);
    }

    private function at(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone(self::TZ));
    }
}
