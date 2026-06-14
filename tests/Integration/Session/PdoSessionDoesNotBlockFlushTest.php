<?php

declare(strict_types=1);

namespace App\Tests\Integration\Session;

use App\Entity\User;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

/**
 * Guards against the regression where PdoSessionHandler's lock_mode opened a
 * transaction on the shared Doctrine PDO, causing EntityManager::flush() to
 * fail with "There is already an active transaction" on any session-touching
 * POST (account password change, display-name change, identity unlink, …).
 *
 * Mirrors the request lifecycle: session is read first (CSRF / firewall), then
 * the controller mutates state and flushes. The flush MUST succeed.
 */
#[SkipDatabaseRollback]
final class PdoSessionDoesNotBlockFlushTest extends KernelTestCase
{
    private const string SESSION_ID = 'flush-regression-test-session-id';

    private ?string $createdUserEmail = null;

    protected function tearDown(): void
    {
        if (self::$booted) {
            $container = self::getContainer();
            /** @var Connection $conn */
            $conn = $container->get('doctrine.dbal.default_connection');
            $conn->executeStatement('DELETE FROM sessions WHERE sess_id = :id', ['id' => self::SESSION_ID]);

            if ($this->createdUserEmail !== null) {
                $conn->executeStatement('DELETE FROM users WHERE email = :email', ['email' => $this->createdUserEmail]);
            }
        }

        parent::tearDown();
    }

    public function testEntityManagerFlushSucceedsWhileSessionLockIsHeld(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var PdoSessionHandler $handler */
        $handler = $container->get('session.handler.pdo');
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $handler->open('', 'PHPSESSID');
        try {
            $handler->read(self::SESSION_ID);

            $this->createdUserEmail = 'flush-regression-' . bin2hex(random_bytes(8)) . '@example.com';
            $user = new User($this->createdUserEmail, 'Flush Regression');
            $em->persist($user);
            $em->flush();

            $this->assertNotNull($user->getId(), 'Flush should persist the user without a transaction collision.');
        } finally {
            $handler->close();
        }
    }
}
