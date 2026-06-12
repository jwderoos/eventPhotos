<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\AuthProvider;
use App\Service\Auth\GoogleUserData;
use App\Tests\Fake\FakeGoogleOAuthClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class GoogleLoginControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private FakeGoogleOAuthClient $fake;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The kernel reboots between requests by default, resetting all service state.
        // Disable reboot so the FakeGoogleOAuthClient singleton persists across the
        // start → callback request pair within a single test.
        $this->client->disableReboot();

        $container = self::getContainer();

        /** @var FakeGoogleOAuthClient $fake */
        $fake = $container->get(FakeGoogleOAuthClient::class);
        $this->fake = $fake;

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $this->em = $em;
    }

    public function testStartRedirectsToProviderAndStashesPurpose(): void
    {
        $this->seedUser();
        $this->client->request(Request::METHOD_GET, '/oauth/google/login');
        self::assertResponseRedirects();
        $this->assertStringStartsWith(
            'https://google.test/',
            $this->client->getResponse()->headers->get('Location') ?? ''
        );
        $this->assertSame('login', $this->fake->lastPurpose);
    }

    public function testCallbackLogsInKnownGoogleUser(): void
    {
        $user = new User('jane@example.com', 'Jane');
        $user->addRole('ROLE_ORGANIZER');
        $user->setPassword('$2y$10$qqqqqqqqqqqqqqqqqqqqqq');

        $identity = new UserIdentity($user, AuthProvider::Google, 'sub-known', 'jane@example.com');
        $user->addIdentity($identity);
        $this->em->persist($user);
        $this->em->persist($identity);
        $this->em->flush();

        $this->client->request(Request::METHOD_GET, '/oauth/google/login');

        $this->fake->setNextUserData(new GoogleUserData('sub-known', 'jane@example.com', true, 'Jane'));
        $this->client->request(Request::METHOD_GET, '/oauth/google/login/callback');

        self::assertResponseRedirects('/admin');
        $this->assertSame($user->getId(), $this->loggedInUserId());
    }

    public function testCallbackAutoLinksWhenUserExistsWithoutGoogleIdentity(): void
    {
        $user = new User('jane@example.com', 'Jane');
        $user->setPassword('$2y$10$qqqqqqqqqqqqqqqqqqqqqq');

        $this->em->persist($user);
        $this->em->flush();

        $this->client->request(Request::METHOD_GET, '/oauth/google/login');
        $this->fake->setNextUserData(new GoogleUserData('sub-new', 'jane@example.com', true, 'Jane'));
        $this->client->request(Request::METHOD_GET, '/oauth/google/login/callback');

        self::assertResponseRedirects('/admin');
        $this->em->clear();
        $reloaded = $this->em->find(User::class, $user->getId());
        $this->assertInstanceOf(User::class, $reloaded);
        $this->assertTrue($reloaded->hasIdentityFor(AuthProvider::Google));
    }

    public function testCallbackRefusesUnverifiedEmail(): void
    {
        $this->seedUser();
        $this->client->request(Request::METHOD_GET, '/oauth/google/login');
        $this->fake->setNextUserData(new GoogleUserData('sub', 'noone@example.com', false, 'X'));
        $this->client->request(Request::METHOD_GET, '/oauth/google/login/callback');

        self::assertResponseRedirects('/login');
        $this->assertNull($this->loggedInUserId());
    }

    public function testCallbackRefusesWhenNoMatchingUser(): void
    {
        $this->seedUser();
        $this->client->request(Request::METHOD_GET, '/oauth/google/login');
        $this->fake->setNextUserData(new GoogleUserData('sub', 'stranger@example.com', true, 'Stranger'));
        $this->client->request(Request::METHOD_GET, '/oauth/google/login/callback');

        self::assertResponseRedirects('/login');
        $this->assertNull($this->loggedInUserId());
    }

    private function loggedInUserId(): ?int
    {
        /** @var TokenStorageInterface $tokenStorage */
        $tokenStorage = self::getContainer()->get('security.token_storage');
        $token = $tokenStorage->getToken();
        $user = $token?->getUser();

        return $user instanceof User ? $user->getId() : null;
    }

    /**
     * The FirstRunBootstrapSubscriber redirects every request to /setup when no users exist.
     * Tests that do not create a test-specific user must call this to bypass the check.
     */
    private function seedUser(): void
    {
        $seed = new User('seed@example.com', 'Seed');
        $seed->setPassword('$2y$10$qqqqqqqqqqqqqqqqqqqqqq');

        $this->em->persist($seed);
        $this->em->flush();
    }
}
