<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class EventBibIndexingTest extends TestCase
{
    private function makeEvent(): Event
    {
        return new Event(
            'run-2026',
            'City Run 2026',
            new DateTimeImmutable('2026-05-01 09:00'),
            new DateTimeImmutable('2026-05-01 12:00'),
            $this->createStub(User::class),
        );
    }

    public function testBibIndexingIsDisabledByDefault(): void
    {
        $this->assertFalse($this->makeEvent()->isBibIndexingEnabled());
    }

    public function testEnableAndDisableBibIndexing(): void
    {
        $event = $this->makeEvent();

        $event->enableBibIndexing();
        $this->assertTrue($event->isBibIndexingEnabled());

        $event->disableBibIndexing();
        $this->assertFalse($event->isBibIndexingEnabled());
    }
}
