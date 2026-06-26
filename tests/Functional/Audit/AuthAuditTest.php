<?php

declare(strict_types=1);

namespace App\Tests\Functional\Audit;

use Symfony\Component\HttpFoundation\Request;
use App\Audit\AuditAction;

final class AuthAuditTest extends AuditWebTestCase
{
    public function testSuccessfulLoginIsAudited(): void
    {
        $this->createUserWithPassword('admin@example.com', 'secret123');

        $crawler = $this->client->request(Request::METHOD_GET, '/login');
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => 'admin@example.com',
            '_password' => 'secret123',
        ]);
        $this->client->submit($form);

        $rows = $this->auditRows(AuditAction::AuthLoginSuccess);
        $this->assertCount(1, $rows);
        $this->assertSame('admin@example.com', $rows[0]->getActorLabel());
    }

    public function testFailedLoginIsAuditedWithAttemptedUsername(): void
    {
        // A user must exist so the first-run subscriber does not redirect to /setup.
        $this->createUserWithPassword('existing@example.com', 'doesnotmatter');

        $crawler = $this->client->request(Request::METHOD_GET, '/login');
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => 'nobody@example.com',
            '_password' => 'wrong',
        ]);
        $this->client->submit($form);

        $rows = $this->auditRows(AuditAction::AuthLoginFailure);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->getActorId());
        $this->assertSame('nobody@example.com', $rows[0]->getContext()['attempted_username']);
    }

    public function testLogoutIsAudited(): void
    {
        $this->createUserWithPassword('logout@example.com', 'secret456');

        $crawler = $this->client->request(Request::METHOD_GET, '/login');
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => 'logout@example.com',
            '_password' => 'secret456',
        ]);
        $this->client->submit($form);
        $this->client->followRedirect();

        // Grab a valid CSRF token for logout from the live session.
        $csrfToken = $this->primeCsrfToken('logout');

        $this->client->request(Request::METHOD_POST, '/logout', ['_csrf_token' => $csrfToken]);

        $rows = $this->auditRows(AuditAction::AuthLogout);
        $this->assertCount(1, $rows);
        $this->assertSame('logout@example.com', $rows[0]->getActorLabel());
    }
}
