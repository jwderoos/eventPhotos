<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MailConfigAuditAction;
use App\Repository\UserMailConfigAuditRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity(repositoryClass: UserMailConfigAuditRepository::class)]
#[ORM\Table(name: 'user_mail_config_audits')]
#[ORM\Index(name: 'idx_mail_audit_user', columns: ['user_id'])]
class UserMailConfigAudit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $actor,
        #[ORM\Column(type: Types::STRING, length: 254)]
        private string $actorEmailSnapshot,
        #[ORM\Column(type: Types::STRING, length: 32, enumType: MailConfigAuditAction::class)]
        private MailConfigAuditAction $action,
        #[ORM\Column(type: Types::STRING, length: 254)]
        private string $fromAddrSnapshot,
    ) {
        if ($actorEmailSnapshot === '') {
            throw new InvalidArgumentException('actor_email_snapshot cannot be empty.');
        }

        if ($fromAddrSnapshot === '') {
            throw new InvalidArgumentException('from_addr_snapshot cannot be empty.');
        }

        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getActorEmailSnapshot(): string
    {
        return $this->actorEmailSnapshot;
    }

    public function getAction(): MailConfigAuditAction
    {
        return $this->action;
    }

    public function getFromAddrSnapshot(): string
    {
        return $this->fromAddrSnapshot;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
