<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Asserts the on_sessions_delete trigger cascades sessions deletes into user_sessions.
 *
 * dama/doctrine-test-bundle wraps each test in a single transaction that rolls back at
 * teardown. PG triggers fire inside the same transaction, so we CAN assert their effect
 * here — the assertion happens before the rollback. Do not disable dama for this test:
 * the rollback is what keeps the suite hermetic.
 */
final class UserSessionsTriggerTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $this->assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    public function testDeletingSessionsRowCascadesIntoUserSessions(): void
    {
        // Insert a minimal users row to satisfy the FK on user_sessions.user_id.
        $userId = $this->connection->fetchOne(
            "INSERT INTO users (email, roles, password, display_name)"
            . " VALUES ('trigger-test@example.com', '[]', 'x', 'Trigger Test') RETURNING id"
        );

        $sessId = 'sess_trigger_test_' . bin2hex(random_bytes(8));

        // Insert into the framework-managed sessions table directly.
        $this->connection->executeStatement(
            'INSERT INTO sessions (sess_id, sess_data, sess_time, sess_lifetime) VALUES (?, ?, ?, ?)',
            [$sessId, '', time(), 2592000],
        );

        // Mirror row in user_sessions.
        $this->connection->executeStatement(
            "INSERT INTO user_sessions (sess_id, user_id, ip, user_agent, created_at, last_seen_at)
             VALUES (?, ?, '127.0.0.1', 'phpunit', NOW(), NOW())",
            [$sessId, $userId],
        );

        $beforeCount = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_sessions WHERE sess_id = ?',
            [$sessId],
        );
        $this->assertIsScalar($beforeCount);
        $this->assertSame(1, (int) $beforeCount);

        // The delete that exercises the trigger.
        $this->connection->executeStatement('DELETE FROM sessions WHERE sess_id = ?', [$sessId]);

        $afterCount = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_sessions WHERE sess_id = ?',
            [$sessId],
        );
        $this->assertIsScalar($afterCount);
        $this->assertSame(0, (int) $afterCount, 'Trigger on_sessions_delete must have removed the user_sessions row.');
    }
}
