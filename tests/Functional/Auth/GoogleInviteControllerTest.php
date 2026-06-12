<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use Symfony\Component\HttpFoundation\Request;
use App\Entity\Invitation;
use App\Entity\User;
use App\Enum\AuthProvider;
use App\Service\Auth\GoogleUserData;
use App\Service\Invitation\InvitationTokenService;
use App\Tests\Fake\FakeGoogleOAuthClient;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GoogleInviteControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private FakeGoogleOAuthClient $fake;

    private EntityManagerInterface $em;

    private InvitationTokenService $tokens;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $c = self::getContainer();

        /** @var FakeGoogleOAuthClient $fake */
        $fake = $c->get(FakeGoogleOAuthClient::class);
        $this->fake = $fake;

        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        $this->em = $em;

        /** @var InvitationTokenService $tokens */
        $tokens = $c->get(InvitationTokenService::class);
        $this->tokens = $tokens;
    }

    /** @return array{0: Invitation, 1: string} */
    private function makeInvite(string $role = 'ROLE_ORGANIZER'): array
    {
        $admin = new User('admin-' . bin2hex(random_bytes(4)) . '@example.com', 'Admin');
        $admin->addRole('ROLE_ADMIN');
        $admin->setPassword('$2y$10$qqqqqqqqqqqqqqqqqqqqqq');

        $this->em->persist($admin);

        $generated = $this->tokens->generate();
        $invite = new Invitation(
            $generated->selector,
            $generated->hashedVerifier,
            $role,
            $admin,
            new DateTimeImmutable('+1 day'),
        );
        $this->em->persist($invite);
        $this->em->flush();

        return [$invite, $generated->plaintext];
    }

    public function testInviteRedemptionViaGoogleCreatesUserAndIdentity(): void
    {
        [$invite, $token] = $this->makeInvite();
        $inviteId = $invite->getId();

        $this->client->request(Request::METHOD_GET, '/oauth/google/invite/' . $token);
        $this->fake->setNextUserData(new GoogleUserData('sub-new', 'new@example.com', true, 'New User'));
        $this->client->request(Request::METHOD_GET, '/oauth/google/invite/callback');

        self::assertResponseRedirects('/admin');

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'new@example.com']);
        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue($user->hasIdentityFor(AuthProvider::Google));

        $reloadedInvite = $this->em->find(Invitation::class, $inviteId);
        $this->assertInstanceOf(Invitation::class, $reloadedInvite);
        $this->assertFalse($reloadedInvite->isPending(), 'invite should be marked used');
    }

    public function testInviteRedemptionRefusedWhenEmailAlreadyExists(): void
    {
        [$invite, $token] = $this->makeInvite();
        $inviteId = $invite->getId();

        $existing = new User('taken@example.com', 'Existing');
        $existing->setPassword('$2y$10$qqqqqqqqqqqqqqqqqqqqqq');

        $this->em->persist($existing);
        $this->em->flush();

        $this->client->request(Request::METHOD_GET, '/oauth/google/invite/' . $token);
        $this->fake->setNextUserData(new GoogleUserData('sub-new', 'taken@example.com', true, 'New'));
        $this->client->request(Request::METHOD_GET, '/oauth/google/invite/callback');

        self::assertResponseRedirects('/invite/' . $token);

        $this->em->clear();
        $reloadedInvite = $this->em->find(Invitation::class, $inviteId);
        $this->assertInstanceOf(Invitation::class, $reloadedInvite);
        $this->assertTrue($reloadedInvite->isPending(), 'invite must NOT be consumed');
    }

    public function testInviteRedemptionRefusedWhenInviteAlreadyUsed(): void
    {
        [$invite, $token] = $this->makeInvite();

        $existing = new User('first@example.com', 'First');
        $existing->setPassword('$2y$10$qqqqqqqqqqqqqqqqqqqqqq');

        $this->em->persist($existing);
        $invite->markUsed($existing, 'first@example.com');
        $this->em->flush();

        $this->client->request(Request::METHOD_GET, '/oauth/google/invite/' . $token);
        // The /oauth/google/invite/{token} endpoint should refuse early with 410, like /invite/{token} does.
        self::assertResponseStatusCodeSame(410);
    }
}
