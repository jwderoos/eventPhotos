<?php

declare(strict_types=1);

namespace App\Tests\Functional\Audit;

use App\Audit\AuditAction;
use App\Entity\Invitation;
use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\AuthProvider;
use App\Service\Auth\GoogleUserData;
use App\Service\Auth\OAuthFailure;
use App\Service\Invitation\InvitationTokenService;
use App\Tests\Fake\FakeGoogleOAuthClient;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;

final class IdentityAuditTest extends AuditWebTestCase
{
    private FakeGoogleOAuthClient $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();

        /** @var FakeGoogleOAuthClient $fake */
        $fake = self::getContainer()->get(FakeGoogleOAuthClient::class);
        $this->fake = $fake;
    }

    public function testCompletingGoogleLinkWritesOAuthLinkAuditRow(): void
    {
        $user = $this->makeUser('me@example.com', 'ROLE_ORGANIZER');
        $user->setPassword('$2y$10$qqqqqqqqqqqqqqqqqqqqqq');

        $this->em->flush();

        $this->client->loginUser($user);

        $this->client->request(Request::METHOD_GET, '/oauth/google/link');

        $this->fake->setNextUserData(new GoogleUserData('sub-link-audit', 'google@example.com', true, 'Me'));
        $this->client->request(Request::METHOD_GET, '/oauth/google/link/callback');

        self::assertResponseRedirects('/account');

        $rows = $this->auditRows(AuditAction::OAuthLink);
        $this->assertCount(1, $rows);

        $context = $rows[0]->getContext();
        $this->assertIsArray($context);
        $this->assertSame('google', $context['provider']);
        $this->assertSame('sub-link-audit', $context['subject']);
        $this->assertIsInt($context['linked_user_id']);
    }

    public function testOAuthFailureOnLinkWritesNoAuditRow(): void
    {
        $user = $this->makeUser('link-fail@example.com', 'ROLE_ORGANIZER');
        $user->setPassword('$2y$10$qqqqqqqqqqqqqqqqqqqqqq');

        $this->em->flush();

        $this->client->loginUser($user);

        $this->client->request(Request::METHOD_GET, '/oauth/google/link');

        $this->fake->setNextFailure(new OAuthFailure('provider_error'));
        $this->client->request(Request::METHOD_GET, '/oauth/google/link/callback');

        self::assertResponseRedirects('/account');
        $this->assertSame([], $this->auditRows(AuditAction::OAuthLink));
    }

    public function testLinkRefusedOnLinkWritesNoAuditRow(): void
    {
        $user = $this->makeUser('link-refused@example.com', 'ROLE_ORGANIZER');
        $user->setPassword('$2y$10$qqqqqqqqqqqqqqqqqqqqqq');

        // Pre-link a Google identity so linkToCurrentUser throws LinkRefused::AlreadyLinkedToCurrent.
        $identity = new UserIdentity($user, AuthProvider::Google, 'existing-sub-link', 'link-refused@example.com');
        $user->addIdentity($identity);
        $this->em->persist($identity);
        $this->em->flush();

        $this->client->loginUser($user);

        $this->client->request(Request::METHOD_GET, '/oauth/google/link');

        // Any valid userData triggers linkToCurrentUser, which sees the existing identity and throws.
        $this->fake->setNextUserData(new GoogleUserData('any-sub', 'other@example.com', true, 'Other'));
        $this->client->request(Request::METHOD_GET, '/oauth/google/link/callback');

        self::assertResponseRedirects('/account');
        $this->assertSame([], $this->auditRows(AuditAction::OAuthLink));
    }

    public function testOAuthFailureOnInviteCallbackWritesNoAuditRow(): void
    {
        /** @var InvitationTokenService $tokens */
        $tokens = self::getContainer()->get(InvitationTokenService::class);

        $admin = new User('admin-invite-oauthfail@example.com', 'Admin');
        $admin->addRole('ROLE_ADMIN');
        $admin->setPassword('$2y$10$qqqqqqqqqqqqqqqqqqqqqq');

        $this->em->persist($admin);

        $generated = $tokens->generate();
        $invite = new Invitation(
            $generated->selector,
            $generated->hashedVerifier,
            'ROLE_ORGANIZER',
            $admin,
            new DateTimeImmutable('+1 day'),
        );
        $this->em->persist($invite);
        $this->em->flush();

        $this->client->request(Request::METHOD_GET, '/oauth/google/invite/' . $generated->plaintext);

        $this->fake->setNextFailure(new OAuthFailure('provider_error'));
        $this->client->request(Request::METHOD_GET, '/oauth/google/invite/callback');

        self::assertResponseRedirects('/invite/' . $generated->plaintext);
        $this->assertSame([], $this->auditRows(AuditAction::InviteRedeem));
    }

    public function testLoginRefusedOnInviteCallbackWritesNoAuditRow(): void
    {
        /** @var InvitationTokenService $tokens */
        $tokens = self::getContainer()->get(InvitationTokenService::class);

        $admin = new User('admin-invite-loginrefused@example.com', 'Admin');
        $admin->addRole('ROLE_ADMIN');
        $admin->setPassword('$2y$10$qqqqqqqqqqqqqqqqqqqqqq');

        $this->em->persist($admin);

        $generated = $tokens->generate();
        $invite = new Invitation(
            $generated->selector,
            $generated->hashedVerifier,
            'ROLE_ORGANIZER',
            $admin,
            new DateTimeImmutable('+1 day'),
        );
        $this->em->persist($invite);

        // Create an existing user with the same email as the Google account data to trigger EmailTaken.
        $existing = new User('taken-email@example.com', 'Existing');
        $existing->addRole('ROLE_ORGANIZER');
        $existing->setPassword('$2y$10$qqqqqqqqqqqqqqqqqqqqqq');

        $this->em->persist($existing);
        $this->em->flush();

        $this->client->request(Request::METHOD_GET, '/oauth/google/invite/' . $generated->plaintext);

        // IdentityCreator will throw LoginRefused(EmailTaken) because 'taken-email@example.com' already exists.
        $this->fake->setNextUserData(new GoogleUserData('sub-taken', 'taken-email@example.com', true, 'New User'));
        $this->client->request(Request::METHOD_GET, '/oauth/google/invite/callback');

        self::assertResponseRedirects('/invite/' . $generated->plaintext);
        $this->assertSame([], $this->auditRows(AuditAction::InviteRedeem));
    }

    public function testRedeemingInviteViaGoogleWritesInviteRedeemAuditRow(): void
    {
        /** @var InvitationTokenService $tokens */
        $tokens = self::getContainer()->get(InvitationTokenService::class);

        $admin = new User('admin-invite-audit@example.com', 'Admin');
        $admin->addRole('ROLE_ADMIN');
        $admin->setPassword('$2y$10$qqqqqqqqqqqqqqqqqqqqqq');

        $this->em->persist($admin);

        $generated = $tokens->generate();
        $invite = new Invitation(
            $generated->selector,
            $generated->hashedVerifier,
            'ROLE_ORGANIZER',
            $admin,
            new DateTimeImmutable('+1 day'),
        );
        $this->em->persist($invite);
        $this->em->flush();

        $inviteId = $invite->getId();

        $this->client->request(Request::METHOD_GET, '/oauth/google/invite/' . $generated->plaintext);
        $this->fake->setNextUserData(new GoogleUserData('sub-invite-audit', 'newinvite@example.com', true, 'New User'));
        $this->client->request(Request::METHOD_GET, '/oauth/google/invite/callback');

        self::assertResponseRedirects('/admin');

        // InviteRedeem action only — AuthLoginSuccess is ALSO written (Task 8), so filter by action.
        $rows = $this->auditRows(AuditAction::InviteRedeem);
        $this->assertCount(1, $rows);

        $context = $rows[0]->getContext();
        $this->assertIsArray($context);
        $this->assertSame('google', $context['provider']);
        $this->assertIsInt($context['created_user_id']);
        $this->assertSame($inviteId, $context['invite_id']);
    }
}
