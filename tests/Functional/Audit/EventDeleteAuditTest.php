<?php

declare(strict_types=1);

namespace App\Tests\Functional\Audit;

use App\Audit\AuditAction;
use Symfony\Component\HttpFoundation\Request;

final class EventDeleteAuditTest extends AuditWebTestCase
{
    public function testDeletingAnEventWritesAnAuditRowWithSnapshot(): void
    {
        $admin = $this->loginAdmin();
        $event = $this->makeEvent('hike-2026', $admin, 'Hike 2026');
        $eventId = (int) $event->getId();

        $this->client->request(Request::METHOD_POST, '/admin/events/' . $eventId . '/delete', [
            '_token' => $this->primeCsrfToken('delete_event_' . $eventId),
        ]);

        self::assertResponseRedirects();

        $rows = $this->auditRows(AuditAction::EventDelete);
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('Event', $row->getTargetType());
        $this->assertSame($eventId, $row->getTargetId());
        $this->assertNotNull($row->getActorId());
        $this->assertArrayHasKey('snapshot', $row->getContext());
        $snapshot = $row->getContext()['snapshot'] ?? null;
        $this->assertIsArray($snapshot);
        $this->assertSame('Hike 2026', $snapshot['name']);
    }
}
