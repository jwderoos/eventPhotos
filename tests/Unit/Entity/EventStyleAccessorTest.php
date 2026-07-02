<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Event;
use App\Entity\EventCollection;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class EventStyleAccessorTest extends TestCase
{
    public function testEventExposesNonNullStyleSettings(): void
    {
        $event = new Event(
            'my-slug',
            'My Event',
            new DateTimeImmutable('2026-07-01 12:00'),
            new DateTimeImmutable('2026-07-01 14:00'),
            new User('owner@example.com', 'Owner'),
        );

        $this->assertTrue($event->getStyle()->isEmpty());
    }

    public function testCollectionExposesNonNullStyleSettings(): void
    {
        $collection = new EventCollection('c-slug', 'Coll', new User('owner@example.com', 'Owner'));

        $this->assertTrue($collection->getStyle()->isEmpty());
    }
}
