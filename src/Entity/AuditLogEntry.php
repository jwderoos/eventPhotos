<?php

declare(strict_types=1);

namespace App\Entity;

use App\Audit\AuditAction;
use App\Repository\AuditLogEntryRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuditLogEntryRepository::class)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_audit_target', columns: ['target_type', 'target_id'])]
#[ORM\Index(name: 'idx_audit_actor', columns: ['actor_id'])]
#[ORM\Index(name: 'idx_audit_action', columns: ['action'])]
class AuditLogEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    /** @param array<string, mixed> $context */
    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 64, enumType: AuditAction::class)]
        private AuditAction $action,
        #[ORM\Column(name: 'actor_id', type: Types::INTEGER, nullable: true)]
        private ?int $actorId,
        #[ORM\Column(name: 'actor_label', type: Types::STRING, length: 180, nullable: true)]
        private ?string $actorLabel,
        #[ORM\Column(name: 'target_type', type: Types::STRING, length: 64, nullable: true)]
        private ?string $targetType,
        #[ORM\Column(name: 'target_id', type: Types::INTEGER, nullable: true)]
        private ?int $targetId,
        #[ORM\Column(name: 'target_label', type: Types::STRING, length: 255, nullable: true)]
        private ?string $targetLabel,
        #[ORM\Column(type: Types::JSON)]
        private array $context,
        #[ORM\Column(name: 'ip_address', type: Types::STRING, length: 45, nullable: true)]
        private ?string $ipAddress,
        #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAction(): AuditAction
    {
        return $this->action;
    }

    public function getActorId(): ?int
    {
        return $this->actorId;
    }

    public function getActorLabel(): ?string
    {
        return $this->actorLabel;
    }

    public function getTargetType(): ?string
    {
        return $this->targetType;
    }

    public function getTargetId(): ?int
    {
        return $this->targetId;
    }

    public function getTargetLabel(): ?string
    {
        return $this->targetLabel;
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
