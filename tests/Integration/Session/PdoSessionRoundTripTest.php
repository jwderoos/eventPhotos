<?php

declare(strict_types=1);

namespace App\Tests\Integration\Session;

use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

#[SkipDatabaseRollback]
final class PdoSessionRoundTripTest extends KernelTestCase
{
    private const string SESSION_ID = 'roundtrip-test-session-id';

    protected function tearDown(): void
    {
        if (self::$booted) {
            $container = self::getContainer();
            /** @var Connection $conn */
            $conn = $container->get('doctrine.dbal.default_connection');
            $conn->executeStatement('DELETE FROM sessions WHERE sess_id = :id', ['id' => self::SESSION_ID]);
        }

        parent::tearDown();
    }

    public function testWriteThenReadReturnsSameData(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var PdoSessionHandler $handler */
        $handler = $container->get('session.handler.pdo');

        $handler->open('', 'PHPSESSID');
        try {
            $handler->write(self::SESSION_ID, 'hello-world');
            $read = $handler->read(self::SESSION_ID);
        } finally {
            $handler->close();
        }

        $this->assertSame('hello-world', $read);
    }
}
