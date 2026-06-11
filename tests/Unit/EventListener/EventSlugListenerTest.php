<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\Entity\Event;
use App\Entity\User;
use App\EventListener\EventSlugListener;
use App\Service\EventSlugGenerator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class EventSlugListenerTest extends TestCase
{
    public function testGeneratesSlugWhenEntitySlugIsEmpty(): void
    {
        $listener = new EventSlugListener(new EventSlugGenerator());
        $event = new Event('', 'Summer Fest', new DateTimeImmutable('2026-07-15'), new User('o@x', 'Owner'));

        $listener->prePersist($event);

        $this->assertMatchesRegularExpression('/^summer-fest-[a-z0-9]{6}$/', $event->getSlug());
    }

    public function testDoesNotTouchSlugWhenAlreadySet(): void
    {
        $listener = new EventSlugListener(new EventSlugGenerator());
        $event = new Event(
            'existing-slug',
            'Original Name',
            new DateTimeImmutable('2026-07-15'),
            new User('o@x', 'Owner'),
        );

        $listener->prePersist($event);

        $this->assertSame('existing-slug', $event->getSlug());
    }
}
