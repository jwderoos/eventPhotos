<?php

declare(strict_types=1);

namespace App\Tests\Integration\Session;

use App\Entity\UserSession;
use Symfony\Component\Security\Core\User\UserInterface;
use App\Entity\User;
use App\EventListener\UserSessionLoginListener;
use App\Repository\UserSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;

final class UserSessionLoginListenerTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private UserSessionLoginListener $listener;

    private UserSessionRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $listener = self::getContainer()->get(UserSessionLoginListener::class);
        $this->assertInstanceOf(UserSessionLoginListener::class, $listener);
        $this->listener = $listener;

        $repo = self::getContainer()->get(UserSessionRepository::class);
        $this->assertInstanceOf(UserSessionRepository::class, $repo);
        $this->repo = $repo;
    }

    public function testCreatesRowOnInteractiveLogin(): void
    {
        $user = $this->makeUser();

        $session = new Session(new MockArraySessionStorage());
        $session->start();

        $sessId = $session->getId();

        $this->insertSessionsRow($sessId);

        $request = new Request();
        $request->setSession($session);
        $request->server->set('REMOTE_ADDR', '8.8.8.8');
        $request->headers->set('User-Agent', 'Mozilla/5.0 Chrome/124.0.0.0');

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        $this->listener->onLogin(new InteractiveLoginEvent($request, $token));

        $row = $this->repo->findOneBySessId($sessId);
        $this->assertInstanceOf(UserSession::class, $row);
        $this->assertSame('8.8.8.8', $row->getIp());
    }

    public function testSkipsNonUserToken(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();

        $sessId = $session->getId();

        $request = new Request();
        $request->setSession($session);
        $request->server->set('REMOTE_ADDR', '1.2.3.4');

        // Use a UserInterface that is NOT an App\Entity\User
        $nonAppUser = new class implements UserInterface {
            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function getPassword(): null
            {
                return null;
            }

            public function getSalt(): null
            {
                return null;
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'other@example.com';
            }
        };

        $token = new UsernamePasswordToken($nonAppUser, 'main', ['ROLE_USER']);

        $this->listener->onLogin(new InteractiveLoginEvent($request, $token));

        // No row should have been created
        $row = $this->repo->findOneBySessId($sessId);
        $this->assertNotInstanceOf(UserSession::class, $row);
    }

    private function makeUser(): User
    {
        $user = new User(
            'listener-' . bin2hex(random_bytes(4)) . '@example.com',
            'Listener Test',
        );
        $user->setPassword('x');
        $user->addRole('ROLE_ORGANIZER');

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function insertSessionsRow(string $sessId): void
    {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO sessions (sess_id, sess_data, sess_time, sess_lifetime) VALUES (?, ?, ?, ?)',
            [$sessId, '', time(), 2592000],
        );
    }
}
