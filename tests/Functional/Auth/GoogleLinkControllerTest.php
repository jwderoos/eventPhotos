<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use Symfony\Component\HttpFoundation\Request;
use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\AuthProvider;
use App\Service\Auth\GoogleUserData;
use App\Tests\Fake\FakeGoogleOAuthClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GoogleLinkControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private FakeGoogleOAuthClient $fake;

    private EntityManagerInterface $em;

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
    }

    private function makeUser(string $email = 'me@example.com'): User
    {
        $u = new User($email, 'Me');
        $u->addRole('ROLE_ORGANIZER');
        $u->setPassword('$2y$10$qqqqqqqqqqqqqqqqqqqqqq');

        $this->em->persist($u);
        $this->em->flush();
        return $u;
    }

    public function testLinkSucceedsWhenUserHasNoGoogleIdentity(): void
    {
        $u = $this->makeUser();
        $this->client->loginUser($u);

        $this->client->request(Request::METHOD_GET, '/oauth/google/link');

        $this->fake->setNextUserData(new GoogleUserData('sub-new', 'work@example.com', true, 'Me'));
        $this->client->request(Request::METHOD_GET, '/oauth/google/link/callback');

        self::assertResponseRedirects('/account');
        $uid = $u->getId();
        $this->em->clear();
        $reloaded = $this->em->find(User::class, $uid);
        $this->assertInstanceOf(User::class, $reloaded);
        $this->assertTrue($reloaded->hasIdentityFor(AuthProvider::Google));
        $this->assertSame('work@example.com', $reloaded->getIdentityFor(AuthProvider::Google)?->getEmail());
    }

    public function testLinkRefusedWhenSubjectBoundToOtherUser(): void
    {
        $other = $this->makeUser('other@example.com');
        $ident = new UserIdentity($other, AuthProvider::Google, 'sub-OTHER', 'other@example.com');
        $other->addIdentity($ident);
        $this->em->persist($ident);
        $this->em->flush();

        $u = $this->makeUser('me@example.com');
        $this->client->loginUser($u);

        $this->client->request(Request::METHOD_GET, '/oauth/google/link');

        $this->fake->setNextUserData(new GoogleUserData('sub-OTHER', 'me@example.com', true, 'Me'));
        $this->client->request(Request::METHOD_GET, '/oauth/google/link/callback');

        self::assertResponseRedirects('/account');
        $uid = $u->getId();
        $this->em->clear();
        $reloaded = $this->em->find(User::class, $uid);
        $this->assertInstanceOf(User::class, $reloaded);
        $this->assertFalse($reloaded->hasIdentityFor(AuthProvider::Google));
    }

    public function testLinkRefusedWhenAlreadyLinked(): void
    {
        $u = $this->makeUser();
        $ident = new UserIdentity($u, AuthProvider::Google, 'sub-OLD', 'me@example.com');
        $u->addIdentity($ident);
        $this->em->persist($ident);
        $this->em->flush();

        $this->client->loginUser($u);
        $this->client->request(Request::METHOD_GET, '/oauth/google/link');

        $this->fake->setNextUserData(new GoogleUserData('sub-NEW', 'me@example.com', true, 'Me'));
        $this->client->request(Request::METHOD_GET, '/oauth/google/link/callback');

        self::assertResponseRedirects('/account');
        $uid = $u->getId();
        $this->em->clear();
        $reloaded = $this->em->find(User::class, $uid);
        $this->assertInstanceOf(User::class, $reloaded);
        $this->assertSame('sub-OLD', $reloaded->getIdentityFor(AuthProvider::Google)?->getSubject());
    }

    public function testLinkRefusedWhenEmailUnverified(): void
    {
        $u = $this->makeUser();
        $this->client->loginUser($u);
        $this->client->request(Request::METHOD_GET, '/oauth/google/link');

        $this->fake->setNextUserData(new GoogleUserData('sub', 'me@example.com', false, 'Me'));
        $this->client->request(Request::METHOD_GET, '/oauth/google/link/callback');

        self::assertResponseRedirects('/account');
        $uid = $u->getId();
        $this->em->clear();
        $reloaded = $this->em->find(User::class, $uid);
        $this->assertInstanceOf(User::class, $reloaded);
        $this->assertFalse($reloaded->hasIdentityFor(AuthProvider::Google));
    }
}
