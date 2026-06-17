<?php

declare(strict_types=1);

namespace App\Tests\Integration\Session;

use App\Entity\UserSession;
use App\Entity\User;
use App\Repository\UserSessionRepository;
use App\Service\Session\UserSessionCreator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserSessionCreatorTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private UserSessionCreator $creator;

    private UserSessionRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $creator = self::getContainer()->get(UserSessionCreator::class);
        $this->assertInstanceOf(UserSessionCreator::class, $creator);
        $this->creator = $creator;

        $repo = self::getContainer()->get(UserSessionRepository::class);
        $this->assertInstanceOf(UserSessionRepository::class, $repo);
        $this->repo = $repo;
    }

    public function testCreatesUserSessionRow(): void
    {
        $user = $this->makeUser();

        $sessId = 'creator_test_' . bin2hex(random_bytes(8));
        $this->insertSessionsRow($sessId);

        $this->creator->create(
            sessId: $sessId,
            user: $user,
            ip: '8.8.8.8',
            userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/124.0.0.0',
        );

        $row = $this->repo->findOneBySessId($sessId);

        $this->assertInstanceOf(UserSession::class, $row);
        $this->assertSame($user->getId(), $row->getUser()->getId());
        $this->assertSame('8.8.8.8', $row->getIp());
        $this->assertNotNull($row->getUserAgentDisplay());
    }

    private function makeUser(): User
    {
        $user = new User(
            'creator-' . bin2hex(random_bytes(4)) . '@example.com',
            'Creator Test',
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
