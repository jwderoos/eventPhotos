<?php

declare(strict_types=1);

namespace App\Tests\Functional\Audit;

use App\Entity\User;
use App\Audit\AuditAction;
use App\Entity\Invitation;
use App\Service\Invitation\InvitationTokenService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final class AdminActionsAuditTest extends AuditWebTestCase
{
    public function testRoleChangeEditRecordsTheRoleDelta(): void
    {
        $this->loginAdmin();
        $target = $this->makeUser('promote-me@example.com', 'ROLE_USER');
        $targetId = (int) $target->getId();

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/users/' . $targetId . '/edit');
        $form = $crawler->selectButton('Save')->form();
        $form['user_edit[role]'] = 'ROLE_ORGANIZER';
        $form['user_edit[displayName]'] = $target->getDisplayName();
        $this->client->submit($form);

        $this->assertResponseRedirects();

        $rows = $this->auditRows(AuditAction::UserEdit);
        $this->assertCount(1, $rows);

        $context = $rows[0]->getContext();
        $this->assertIsArray($context);
        $this->assertArrayHasKey('changes', $context);
        $changes = $context['changes'];
        $this->assertIsArray($changes);
        $this->assertArrayHasKey('role', $changes);
        $roleChange = $changes['role'];
        $this->assertIsArray($roleChange);
        $this->assertSame('ROLE_USER', $roleChange[0]);
        $this->assertSame('ROLE_ORGANIZER', $roleChange[1]);
    }

    public function testRevokingAnInvitationIsAudited(): void
    {
        $admin = $this->loginAdmin();
        $invite = $this->createPendingInvitation($admin);
        $inviteId = (int) $invite->getId();

        $this->client->request(Request::METHOD_POST, '/admin/invites/' . $inviteId . '/revoke', [
            '_token' => $this->primeCsrfToken('invite_revoke_' . $inviteId),
        ]);

        $this->assertResponseRedirects();
        $this->assertCount(1, $this->auditRows(AuditAction::InviteRevoke));
    }

    public function testCreatingAUserWritesAnAuditRow(): void
    {
        $this->loginAdmin();

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/users/new');
        $form = $crawler->selectButton('Create')->form();
        $form['user_create[email]'] = 'newuser-audit@example.com';
        $form['user_create[displayName]'] = 'New Audit User';
        $form['user_create[role]'] = 'ROLE_ORGANIZER';
        $this->client->submit($form);

        $this->assertResponseRedirects();

        $rows = $this->auditRows(AuditAction::UserCreate);
        $this->assertCount(1, $rows);
        $context = $rows[0]->getContext();
        $this->assertIsArray($context);
        $this->assertArrayHasKey('created_id', $context);
    }

    private function createPendingInvitation(User $createdBy): Invitation
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var InvitationTokenService $tokens */
        $tokens = self::getContainer()->get(InvitationTokenService::class);

        $gen = $tokens->generate();
        $invite = new Invitation(
            selector: $gen->selector,
            hashedVerifier: $gen->hashedVerifier,
            role: 'ROLE_ORGANIZER',
            createdBy: $createdBy,
            expiresAt: new DateTimeImmutable('+7 days'),
        );
        $em->persist($invite);
        $em->flush();

        return $invite;
    }
}
