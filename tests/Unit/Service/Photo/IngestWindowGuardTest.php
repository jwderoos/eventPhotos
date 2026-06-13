<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Photo;

use App\Entity\Event;
use App\Entity\User;
use App\Service\Photo\IngestWindowGuard;
use App\Service\Photo\PhotoRejected;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class IngestWindowGuardTest extends TestCase
{
    private IngestWindowGuard $guard;

    private Event $event;

    protected function setUp(): void
    {
        $this->guard = new IngestWindowGuard();
        $this->event = new Event(
            'demo',
            'Demo',
            new DateTimeImmutable('2026-06-10 10:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-10 14:00', new DateTimeZone('UTC')),
            new User('o@example.test', 'O'),
        );
    }

    public function testAcceptsInsideWindow(): void
    {
        $this->expectNotToPerformAssertions();

        $this->guard->assertWithinWindow(
            $this->event,
            new DateTimeImmutable('2026-06-10 12:00', new DateTimeZone('UTC')),
        );
    }

    public function testAcceptsOnLowerBoundary(): void
    {
        $this->expectNotToPerformAssertions();

        $this->guard->assertWithinWindow(
            $this->event,
            new DateTimeImmutable('2026-06-10 09:30', new DateTimeZone('UTC')),
        );
    }

    public function testAcceptsOnUpperBoundary(): void
    {
        $this->expectNotToPerformAssertions();

        $this->guard->assertWithinWindow(
            $this->event,
            new DateTimeImmutable('2026-06-10 14:30', new DateTimeZone('UTC')),
        );
    }

    public function testRejectsBeforeLowerBoundary(): void
    {
        $this->expectException(PhotoRejected::class);
        $this->expectExceptionMessageMatches('/outside the event window/');

        $this->guard->assertWithinWindow(
            $this->event,
            new DateTimeImmutable('2026-06-10 09:29:59', new DateTimeZone('UTC')),
        );
    }

    public function testRejectsAfterUpperBoundary(): void
    {
        $this->expectException(PhotoRejected::class);
        $this->expectExceptionMessageMatches('/outside the event window/');

        $this->guard->assertWithinWindow(
            $this->event,
            new DateTimeImmutable('2026-06-10 14:30:01', new DateTimeZone('UTC')),
        );
    }

    public function testRejectionMessageMentionsBothTimestamps(): void
    {
        try {
            $this->guard->assertWithinWindow(
                $this->event,
                new DateTimeImmutable('2026-06-11 09:00', new DateTimeZone('UTC')),
            );
            $this->fail('Expected PhotoRejected to be thrown.');
        } catch (PhotoRejected $photoRejected) {
            $message = $photoRejected->getMessage();
            $this->assertStringContainsString('2026-06-11 09:00:00', $message);
            $this->assertStringContainsString('2026-06-10 10:00', $message);
            $this->assertStringContainsString('2026-06-10 14:00', $message);
        }
    }
}
