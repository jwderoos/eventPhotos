<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Invitation;
use App\Entity\User;
use App\Service\Invitation\GeneratedToken;
use App\Service\Invitation\InvitationTokenService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class InvitationRedemptionFlowTest extends WebTestCase
{
    public function testAnonymousGetWithValidTokenRendersForm(): void
    {
        $browser = self::createClient();
        $this->ensureUserExists();

        [, $generated] = $this->createPendingInvite();

        $browser->request(Request::METHOD_GET, '/invite/' . $generated->plaintext);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSelectorTextContains('h1', "You've been invited");
    }

    public function testLoggedInUserSeesAlreadySignedInPage(): void
    {
        $browser = self::createClient();
        $existing = $this->ensureUserExists();
        $browser->loginUser($existing);

        [, $generated] = $this->createPendingInvite();

        $browser->request(Request::METHOD_GET, '/invite/' . $generated->plaintext);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSelectorExists('[data-testid="invite-already-signed-in"]');
    }

    public function testMalformedTokenRendersInvalidPage(): void
    {
        $browser = self::createClient();
        $this->ensureUserExists();

        // Strict route requirement makes "not-hex" produce 404. Use a hex-but-untraceable token instead.
        $browser->request(Request::METHOD_GET, '/invite/' . str_repeat('a', 32) . '.' . str_repeat('b', 64));

        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        self::assertSelectorExists('[data-testid="invite-invalid"]');
    }

    public function testExpiredTokenRendersInvalidPage(): void
    {
        $browser = self::createClient();
        $this->ensureUserExists();

        [, $generated] = $this->createPendingInvite(expiresAt: new DateTimeImmutable('-1 minute'));

        $browser->request(Request::METHOD_GET, '/invite/' . $generated->plaintext);

        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        self::assertSelectorExists('[data-testid="invite-invalid"]');
    }

    public function testRevokedTokenRendersInvalidPage(): void
    {
        $browser = self::createClient();
        $admin = $this->ensureUserExists();

        [$invite, $generated] = $this->createPendingInvite();
        $invite->revoke($admin);
        $this->em()->flush();

        $browser->request(Request::METHOD_GET, '/invite/' . $generated->plaintext);

        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        self::assertSelectorExists('[data-testid="invite-invalid"]');
    }

    public function testTamperedVerifierRendersInvalidPage(): void
    {
        $browser = self::createClient();
        $this->ensureUserExists();

        [, $generated] = $this->createPendingInvite();
        $verifierLength = strlen($generated->plaintext) - strlen($generated->selector) - 1;
        $tampered = $generated->selector . '.' . str_repeat('0', $verifierLength);

        $browser->request(Request::METHOD_GET, '/invite/' . $tampered);

        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        self::assertSelectorExists('[data-testid="invite-invalid"]');
    }

    public function testPostHappyPathCreatesUserAndLogsIn(): void
    {
        $browser = self::createClient();
        $this->ensureUserExists();

        [$invite, $generated] = $this->createPendingInvite();

        $browser->request(Request::METHOD_GET, '/invite/' . $generated->plaintext);
        $browser->submitForm('Create account', [
            'invitation_redeem[email]'            => 'new-user@example.com',
            'invitation_redeem[displayName]'      => 'New User',
            'invitation_redeem[password][first]'  => 'a-very-strong-passphrase',
            'invitation_redeem[password][second]' => 'a-very-strong-passphrase',
        ]);

        self::assertResponseRedirects('/admin');

        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $created = $em->getRepository(User::class)->findOneBy(['email' => 'new-user@example.com']);
        $this->assertInstanceOf(User::class, $created);
        $this->assertContains('ROLE_ORGANIZER', $created->getRoles());

        $inviteId = $invite->getId();
        $this->assertNotNull($inviteId);
        $refreshed = $em->find(Invitation::class, $inviteId);
        $this->assertInstanceOf(Invitation::class, $refreshed);
        $this->assertSame('used', $refreshed->status()->value);
        $this->assertSame('new-user@example.com', $refreshed->getEmail());
        $this->assertSame($created->getId(), $refreshed->getUsedBy()?->getId());
    }

    public function testPostEmailCollisionLeavesInvitePending(): void
    {
        $browser = self::createClient();
        $this->ensureUserExists();

        [$invite, $generated] = $this->createPendingInvite();

        $browser->request(Request::METHOD_GET, '/invite/' . $generated->plaintext);
        $browser->submitForm('Create account', [
            'invitation_redeem[email]'            => 'admin-redeem@example.com',
            'invitation_redeem[displayName]'      => 'Should Fail',
            'invitation_redeem[password][first]'  => 'a-very-strong-passphrase',
            'invitation_redeem[password][second]' => 'a-very-strong-passphrase',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('body', 'An account already exists');

        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $inviteId = $invite->getId();
        $this->assertNotNull($inviteId);
        $refreshed = $em->find(Invitation::class, $inviteId);
        $this->assertInstanceOf(Invitation::class, $refreshed);
        $this->assertSame('pending', $refreshed->status()->value);
    }

    public function testPostPasswordMismatchLeavesInvitePending(): void
    {
        $browser = self::createClient();
        $this->ensureUserExists();

        [$invite, $generated] = $this->createPendingInvite();

        $browser->request(Request::METHOD_GET, '/invite/' . $generated->plaintext);
        $browser->submitForm('Create account', [
            'invitation_redeem[email]'            => 'mismatch@example.com',
            'invitation_redeem[displayName]'      => 'Mismatch',
            'invitation_redeem[password][first]'  => 'a-very-strong-passphrase',
            'invitation_redeem[password][second]' => 'something-else-entirely',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $inviteId = $invite->getId();
        $this->assertNotNull($inviteId);
        $refreshed = $em->find(Invitation::class, $inviteId);
        $this->assertInstanceOf(Invitation::class, $refreshed);
        $this->assertSame('pending', $refreshed->status()->value);
    }

    public function testPostSecondTimeRendersInvalidPage(): void
    {
        $browser = self::createClient();
        $this->ensureUserExists();

        [$invite, $generated] = $this->createPendingInvite();

        // First redemption (success).
        $browser->request(Request::METHOD_GET, '/invite/' . $generated->plaintext);
        $browser->submitForm('Create account', [
            'invitation_redeem[email]'            => 'first@example.com',
            'invitation_redeem[displayName]'      => 'First',
            'invitation_redeem[password][first]'  => 'a-very-strong-passphrase',
            'invitation_redeem[password][second]' => 'a-very-strong-passphrase',
        ]);
        self::assertResponseRedirects('/admin');

        // Second attempt — fresh session (same kernel, no cookies/history).
        $browser->restart();
        $browser->request(Request::METHOD_GET, '/invite/' . $generated->plaintext);
        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
    }

    /**
     * @return array{Invitation, GeneratedToken}
     */
    private function createPendingInvite(?DateTimeImmutable $expiresAt = null): array
    {
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var InvitationTokenService $tokens */
        $tokens = $container->get(InvitationTokenService::class);

        $admin = $this->ensureUserExists();
        $generated = $tokens->generate();

        $invite = new Invitation(
            selector: $generated->selector,
            hashedVerifier: $generated->hashedVerifier,
            role: 'ROLE_ORGANIZER',
            createdBy: $admin,
            expiresAt: $expiresAt ?? new DateTimeImmutable('+7 days'),
        );
        $em->persist($invite);
        $em->flush();

        return [$invite, $generated];
    }

    private function ensureUserExists(): User
    {
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $existing = $em->getRepository(User::class)->findOneBy(['email' => 'admin-redeem@example.com']);
        if ($existing instanceof User) {
            return $existing;
        }

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User('admin-redeem@example.com', 'AdminRedeem');
        $user->addRole('ROLE_ADMIN');
        $user->setPassword($hasher->hashPassword($user, 'correct horse battery'));

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        return $em;
    }
}
