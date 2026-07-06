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
        $form = $crawler->filter('#user-form')->form();
        $form['user_edit[role]'] = 'ROLE_ORGANIZER';
        $form['user_edit[displayName]'] = $target->getDisplayName();
        $this->client->submit($form);

        $this->assertResponseRedirects();

        // Role change must produce a UserRoleChange row, NOT a generic UserEdit row.
        $roleChangeRows = $this->auditRows(AuditAction::UserRoleChange);
        $this->assertCount(1, $roleChangeRows, 'Expected exactly one UserRoleChange audit row');

        $context = $roleChangeRows[0]->getContext();
        $this->assertIsArray($context);
        $this->assertArrayHasKey('changes', $context);
        $changes = $context['changes'];
        $this->assertIsArray($changes);
        $this->assertArrayHasKey('role', $changes);
        $roleChange = $changes['role'];
        $this->assertIsArray($roleChange);
        $this->assertSame('ROLE_USER', $roleChange[0]);
        $this->assertSame('ROLE_ORGANIZER', $roleChange[1]);

        // Verify the override: no generic UserEdit row was written for this request.
        $userEditRows = $this->auditRows(AuditAction::UserEdit);
        $this->assertSame([], $userEditRows, 'No UserEdit row should be written when role changes');
    }

    public function testNameOnlyEditProducesUserEditRow(): void
    {
        $this->loginAdmin();
        $target = $this->makeUser('rename-me@example.com', 'ROLE_USER');
        $targetId = (int) $target->getId();

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/users/' . $targetId . '/edit');
        $form = $crawler->filter('#user-form')->form();
        $form['user_edit[displayName]'] = 'New Display Name';
        // Role field left as-is (no role change).
        $this->client->submit($form);

        $this->assertResponseRedirects();

        // Name-only edit → generic UserEdit row.
        $userEditRows = $this->auditRows(AuditAction::UserEdit);
        $this->assertCount(1, $userEditRows, 'Name-only edit must produce a UserEdit row');
        // No UserRoleChange row.
        $roleChangeRows = $this->auditRows(AuditAction::UserRoleChange);
        $this->assertSame([], $roleChangeRows, 'No UserRoleChange row expected for name-only edit');
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

    public function testDeleteBlockedByOwnedEventsWritesNoAuditRow(): void
    {
        $this->loginAdmin();
        $target = $this->makeUser('owned-events@example.com', 'ROLE_ORGANIZER');
        $this->makeEvent('test-event-del-block', $target);
        $targetId = (int) $target->getId();

        $this->client->request(Request::METHOD_POST, '/admin/users/' . $targetId . '/delete', [
            '_token' => $this->primeCsrfToken('delete_user_' . $targetId),
        ]);

        $this->assertResponseRedirects();
        $this->assertSame([], $this->auditRows(AuditAction::UserDelete));
    }

    public function testRevokeAlreadyRevokedInviteWritesNoAuditRow(): void
    {
        $admin = $this->loginAdmin();
        $invite = $this->createPendingInvitation($admin);
        // Revoke it directly via domain method so it is no longer pending.
        $invite->revoke($admin);

        $this->em->flush();
        $inviteId = (int) $invite->getId();

        $this->client->request(Request::METHOD_POST, '/admin/invites/' . $inviteId . '/revoke', [
            '_token' => $this->primeCsrfToken('invite_revoke_' . $inviteId),
        ]);

        $this->assertResponseRedirects();
        $this->assertSame([], $this->auditRows(AuditAction::InviteRevoke));
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
