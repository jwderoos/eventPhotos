<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class EventBannerFieldsTest extends TestCase
{
    public function testBannerFieldsDefaultNullAndAreSettable(): void
    {
        $owner = new User('o@example.com', 'O');
        $owner->setPassword('x');

        $event = new Event(
            'banner-fields',
            'Banner Fields',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );

        $this->assertNull($event->getBannerFilename());
        $this->assertNotInstanceOf(DateTimeImmutable::class, $event->getBannerUpdatedAt());

        $stamp = new DateTimeImmutable('2026-07-07 12:00');
        $event->setBannerFilename('event-1.jpg');
        $event->setBannerUpdatedAt($stamp);

        $this->assertSame('event-1.jpg', $event->getBannerFilename());
        $this->assertSame($stamp, $event->getBannerUpdatedAt());
    }
}
