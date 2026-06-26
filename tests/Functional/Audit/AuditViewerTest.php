<?php

declare(strict_types=1);

namespace App\Tests\Functional\Audit;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuditViewerTest extends AuditWebTestCase
{
    public function testOrganizerIsForbidden(): void
    {
        $this->loginOrganizer();
        $this->client->request(Request::METHOD_GET, '/admin/audit');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAdminSeesAuditRowsAndCanFilterByAction(): void
    {
        $admin = $this->loginAdmin();
        $event = $this->makeEvent('hike-2026', $admin);
        $eventId = (int) $event->getId();
        $this->client->request(Request::METHOD_POST, '/admin/events/' . $eventId . '/delete', [
            '_token' => $this->primeCsrfToken('delete_event_' . $eventId),
        ]);

        $this->client->request(Request::METHOD_GET, '/admin/audit');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'event.delete');

        $this->client->request(Request::METHOD_GET, '/admin/audit?action=auth.login_success');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('table', 'event.delete');
    }
}
