<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Audit\AuditAction;
use App\Entity\AuditLogEntry;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AuditLogEntryTest extends TestCase
{
    public function testConstructsAndExposesAllFields(): void
    {
        $at = new DateTimeImmutable('2026-06-12 12:00:00');
        $entry = new AuditLogEntry(
            AuditAction::EventDelete,
            42,
            'admin@example.com',
            'Event',
            7,
            'Hike 2026 (hike-2026-ab12)',
            ['snapshot' => ['name' => 'Hike 2026']],
            '203.0.113.9',
            $at,
        );

        $this->assertNull($entry->getId());
        $this->assertSame(AuditAction::EventDelete, $entry->getAction());
        $this->assertSame(42, $entry->getActorId());
        $this->assertSame('admin@example.com', $entry->getActorLabel());
        $this->assertSame('Event', $entry->getTargetType());
        $this->assertSame(7, $entry->getTargetId());
        $this->assertSame('Hike 2026 (hike-2026-ab12)', $entry->getTargetLabel());
        $this->assertSame(['snapshot' => ['name' => 'Hike 2026']], $entry->getContext());
        $this->assertSame('203.0.113.9', $entry->getIpAddress());
        $this->assertSame($at, $entry->getCreatedAt());
    }

    public function testAllowsAnonymousActorAndNoTarget(): void
    {
        $entry = new AuditLogEntry(
            AuditAction::AuthLoginFailure,
            null,
            null,
            null,
            null,
            null,
            ['attempted_username' => 'nobody@example.com'],
            '203.0.113.9',
            new DateTimeImmutable('2026-06-12 12:00:00'),
        );

        $this->assertNull($entry->getActorId());
        $this->assertNull($entry->getTargetType());
        $this->assertSame(['attempted_username' => 'nobody@example.com'], $entry->getContext());
    }
}
