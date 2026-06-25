<?php

declare(strict_types=1);

namespace App\Audit;

use App\Entity\AuditLogEntry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Throwable;

final readonly class AuditLogger
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(
        AuditAction $action,
        ?int $actorId,
        ?string $actorLabel,
        ?string $targetType,
        ?int $targetId,
        ?string $targetLabel,
        array $context = [],
        ?string $ip = null,
    ): void {
        try {
            $entry = new AuditLogEntry(
                $action,
                $actorId,
                $actorLabel,
                $targetType,
                $targetId,
                $targetLabel,
                $context,
                $ip,
                $this->clock->now(),
            );

            $this->em->persist($entry);
            $this->em->flush();
        } catch (Throwable $throwable) {
            // Auditing must never break the action it records.
            $this->logger->error('Failed to write audit log entry.', [
                'action' => $action->value,
                'exception' => $throwable,
            ]);
        }
    }
}
