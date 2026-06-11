<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Invitation;
use App\Entity\User;
use App\Repository\InvitationRepository;
use App\Service\Invitation\InvitationTokenService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

final class AdminInvitationFlowTest extends WebTestCase
{
    public function testAdminCanCreateInviteAndSeeOneTimeUrlBanner(): void
    {
        $browser = self::createClient();
        $this->loginAsAdmin($browser);

        $browser->request(Request::METHOD_GET, '/admin/invites/new');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $browser->submitForm('Create invite');

        self::assertResponseRedirects('/admin/invites');
        $browser->followRedirect();

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSelectorExists('[data-testid="invite-new-url"]');

        // Re-visit: the banner should be gone (flash consumed).
        $browser->request(Request::METHOD_GET, '/admin/invites');
        self::assertSelectorNotExists('[data-testid="invite-new-url"]');

        /** @var InvitationRepository $repo */
        $repo = self::getContainer()->get(InvitationRepository::class);
        $this->assertCount(1, $repo->findAllOrderedByCreated());
    }

    public function testOrganizerCannotAccessInviteRoutes(): void
    {
        $browser = self::createClient();
        $this->loginAsOrganizer($browser);

        $browser->request(Request::METHOD_GET, '/admin/invites');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $browser->request(Request::METHOD_GET, '/admin/invites/new');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAdminCanRevokePendingInvite(): void
    {
        $browser = self::createClient();
        $admin = $this->loginAsAdmin($browser);

        $invite = $this->createPendingInvite($admin);
        $inviteId = $invite->getId();
        $this->assertNotNull($inviteId);

        $browser->request(Request::METHOD_POST, '/admin/invites/' . $inviteId . '/revoke', [
            '_token' => $this->csrfToken($browser, 'invite_revoke_' . $inviteId),
        ]);
        self::assertResponseRedirects('/admin/invites');

        /** @var InvitationRepository $repo */
        $repo = self::getContainer()->get(InvitationRepository::class);
        $reloaded = $repo->findBySelector($invite->getSelector());
        $this->assertInstanceOf(Invitation::class, $reloaded);
        $this->assertSame('revoked', $reloaded->status()->value);
    }

    public function testRevokeOnAlreadyTerminalInviteIsNoOp(): void
    {
        $browser = self::createClient();
        $admin = $this->loginAsAdmin($browser);

        $invite = $this->createPendingInvite($admin);
        $invite->revoke($admin);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $em);
        $em->flush();

        $inviteId = $invite->getId();
        $this->assertNotNull($inviteId);

        $browser->request(Request::METHOD_POST, '/admin/invites/' . $inviteId . '/revoke', [
            '_token' => $this->csrfToken($browser, 'invite_revoke_' . $inviteId),
        ]);
        self::assertResponseRedirects('/admin/invites');

        /** @var InvitationRepository $repo */
        $repo = self::getContainer()->get(InvitationRepository::class);
        $reloaded = $repo->findBySelector($invite->getSelector());
        $this->assertInstanceOf(Invitation::class, $reloaded);
        $this->assertSame('revoked', $reloaded->status()->value);
    }

    public function testRevokeRejectsMissingCsrf(): void
    {
        $browser = self::createClient();
        $admin = $this->loginAsAdmin($browser);

        $invite = $this->createPendingInvite($admin);
        $inviteId = $invite->getId();
        $this->assertNotNull($inviteId);

        $browser->request(Request::METHOD_POST, '/admin/invites/' . $inviteId . '/revoke');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function loginAsAdmin(KernelBrowser $browser): User
    {
        return $this->createAndLogin($browser, 'admin@example.com', 'ROLE_ADMIN');
    }

    private function loginAsOrganizer(KernelBrowser $browser): User
    {
        return $this->createAndLogin($browser, 'organizer@example.com', 'ROLE_ORGANIZER');
    }

    private function createAndLogin(KernelBrowser $browser, string $email, string $role): User
    {
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User($email, ucfirst(explode('@', $email)[0]));
        $user->addRole($role);
        $user->setPassword($hasher->hashPassword($user, 'correct horse battery'));

        $em->persist($user);
        $em->flush();

        $browser->loginUser($user);

        return $user;
    }

    private function createPendingInvite(User $admin): Invitation
    {
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var InvitationTokenService $tokens */
        $tokens = $container->get(InvitationTokenService::class);

        $gen = $tokens->generate();
        $invite = new Invitation(
            selector: $gen->selector,
            hashedVerifier: $gen->hashedVerifier,
            role: 'ROLE_ORGANIZER',
            createdBy: $admin,
            expiresAt: new DateTimeImmutable('+7 days'),
        );
        $em->persist($invite);
        $em->flush();

        return $invite;
    }

    private function csrfToken(KernelBrowser $browser, string $id): string
    {
        // Make a GET request first to establish a session, then inject the token directly.
        $browser->request(Request::METHOD_GET, '/admin/invites');

        $session = $browser->getRequest()->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }

        $token = bin2hex(random_bytes(16));
        $session->set(SessionTokenStorage::SESSION_NAMESPACE . '/' . $id, $token);
        $session->save();

        return $token;
    }
}
