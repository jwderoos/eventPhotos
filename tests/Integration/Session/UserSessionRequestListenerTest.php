<?php

declare(strict_types=1);

namespace App\Tests\Integration\Session;

use App\Entity\UserSession;
use App\Entity\User;
use App\EventListener\UserSessionRequestListener;
use App\Repository\UserSessionRepository;
use App\Service\Session\UserSessionCreator;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class UserSessionRequestListenerTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private UserSessionRepository $repo;

    private UserSessionCreator $creator;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $repo = self::getContainer()->get(UserSessionRepository::class);
        $this->assertInstanceOf(UserSessionRepository::class, $repo);
        $this->repo = $repo;

        $creator = self::getContainer()->get(UserSessionCreator::class);
        $this->assertInstanceOf(UserSessionCreator::class, $creator);
        $this->creator = $creator;

        $connection = self::getContainer()->get(Connection::class);
        $this->assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    public function testLazyCreatesRowWhenMissing(): void
    {
        $user = $this->makeUser();
        $sessId = 'lazy_' . bin2hex(random_bytes(4));

        $event = $this->makeRequestEvent($sessId);

        $this->assertNotInstanceOf(UserSession::class, $this->repo->findOneBySessId($sessId));

        $listener = $this->makeListener(new MockClock(new DateTimeImmutable('2026-06-17 12:00:00')), $user);
        $listener->onRequest($event);

        $this->assertNotNull($this->repo->findOneBySessId($sessId));
    }

    public function testRefreshesLastSeenAtOnlyWhenStale(): void
    {
        $user = $this->makeUser();
        $sessId = 'throttle_' . bin2hex(random_bytes(4));
        $clock = new MockClock(new DateTimeImmutable('2026-06-17 12:00:00'));

        // First request creates the row.
        $event1 = $this->makeRequestEvent($sessId);
        $this->makeListener($clock, $user)->onRequest($event1);

        $first = $this->repo->findOneBySessId($sessId);
        $this->assertInstanceOf(UserSession::class, $first);
        $firstLastSeen = $first->getLastSeenAt();

        // 30 seconds later — should NOT update last_seen_at.
        $clock->modify('+30 seconds');
        $this->em->clear();
        $event2 = $this->makeRequestEvent($sessId);
        $this->makeListener($clock, $user)->onRequest($event2);

        $second = $this->repo->findOneBySessId($sessId);
        $this->assertInstanceOf(UserSession::class, $second);
        $this->assertSame($firstLastSeen->getTimestamp(), $second->getLastSeenAt()->getTimestamp());

        // 90 more seconds (total 120 s) — SHOULD update last_seen_at.
        $clock->modify('+90 seconds');
        $this->em->clear();
        $event3 = $this->makeRequestEvent($sessId);
        $this->makeListener($clock, $user)->onRequest($event3);

        $third = $this->repo->findOneBySessId($sessId);
        $this->assertInstanceOf(UserSession::class, $third);
        $this->assertGreaterThan($firstLastSeen->getTimestamp(), $third->getLastSeenAt()->getTimestamp());
    }

    private function makeListener(MockClock $clock, User $user): UserSessionRequestListener
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return new UserSessionRequestListener(
            security: $security,
            creator: $this->creator,
            repo: $this->repo,
            connection: $this->connection,
            clock: $clock,
        );
    }

    private function makeUser(): User
    {
        $user = new User(
            'listener-req-' . bin2hex(random_bytes(4)) . '@example.com',
            'Request Listener Test',
        );
        $user->setPassword('x');
        $user->addRole('ROLE_ORGANIZER');

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function makeRequestEvent(string $sessId): RequestEvent
    {
        $storage = new MockArraySessionStorage();
        $storage->setId($sessId);

        $session = new Session($storage);
        $session->start();

        $request = new Request();
        $request->setSession($session);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $request->headers->set('User-Agent', 'PHPUnit Test Browser');

        $kernel = self::$kernel;
        $this->assertInstanceOf(HttpKernelInterface::class, $kernel);

        $this->insertSessionsRow($sessId);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function insertSessionsRow(string $sessId): void
    {
        // Only insert if not already present (second/third calls in throttle test reuse the same sessId).
        $exists = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM sessions WHERE sess_id = ?',
            [$sessId],
        );
        $this->assertIsScalar($exists);

        if ((int) $exists === 0) {
            $this->em->getConnection()->executeStatement(
                'INSERT INTO sessions (sess_id, sess_data, sess_time, sess_lifetime) VALUES (?, ?, ?, ?)',
                [$sessId, '', time(), 2592000],
            );
        }
    }
}
