<?php

declare(strict_types=1);

namespace App\Tests\Integration\Audit;

use RuntimeException;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Repository\AuditLogEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class AuditLoggerTest extends KernelTestCase
{
    public function testLogPersistsAnEntryStampedWithTheClock(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var AuditLogger $logger */
        $logger = $container->get(AuditLogger::class);
        $this->assertInstanceOf(AuditLogger::class, $logger);

        $logger->log(
            AuditAction::EventDelete,
            42,
            'admin@example.com',
            'Event',
            7,
            'Hike 2026',
            ['snapshot' => ['name' => 'Hike 2026']],
            '203.0.113.9',
        );

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();

        /** @var AuditLogEntryRepository $repo */
        $repo = $container->get(AuditLogEntryRepository::class);
        $rows = $repo->findAll();

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame(AuditAction::EventDelete, $row->getAction());
        $this->assertSame(42, $row->getActorId());
        $this->assertSame('Hike 2026', $row->getTargetLabel());
        // MockClock is bound to 2026-06-12 12:00:00 UTC in when@test.
        $this->assertSame('2026-06-12 12:00:00', $row->getCreatedAt()->format('Y-m-d H:i:s'));
    }

    public function testLogNeverThrowsAndDelegatesToLoggerOnFlushFailure(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(new RuntimeException('db down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $clock = new MockClock('2026-06-25T00:00:00Z');

        $auditLogger = new AuditLogger($em, $clock, $logger);

        // Must not throw — auditing never breaks the caller.
        // Reaching the end of this method (with the logger->error expectation satisfied) proves the contract.
        $auditLogger->log(
            AuditAction::AuthLoginFailure,
            null,
            null,
            null,
            null,
            null,
        );
    }
}
