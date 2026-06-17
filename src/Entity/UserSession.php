<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserSessionRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserSessionRepository::class)]
#[ORM\Table(name: 'user_sessions')]
#[ORM\Index(name: 'idx_user_sessions_user_id', columns: ['user_id'])]
class UserSession
{
    #[ORM\Column(name: 'label', type: Types::STRING, length: 64, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(name: 'last_seen_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $lastSeenAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'sess_id', type: Types::STRING, length: 128)]
        private string $sessId,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(name: 'ip', type: Types::STRING, length: 45)]
        private string $ip,
        #[ORM\Column(name: 'user_agent', type: Types::TEXT)]
        private string $userAgent,
        #[ORM\Column(name: 'user_agent_display', type: Types::STRING, length: 128, nullable: true)]
        private ?string $userAgentDisplay,
        #[ORM\Column(name: 'country_code', type: Types::STRING, length: 2, nullable: true)]
        private ?string $countryCode,
        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $createdAt,
    ) {
        if ($sessId === '') {
            throw new InvalidArgumentException('UserSession sess_id must not be empty.');
        }

        $this->lastSeenAt = $this->createdAt;
    }

    public function getSessId(): string
    {
        return $this->sessId;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function getUserAgentDisplay(): ?string
    {
        return $this->userAgentDisplay;
    }

    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastSeenAt(): DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLabel(?string $label): void
    {
        if ($label !== null) {
            $label = trim($label);
            if ($label === '') {
                $label = null;
            }
        }

        if ($label !== null) {
            $label = mb_substr($label, 0, 64);
        }

        $this->label = $label;
    }

    public function touch(DateTimeImmutable $when): void
    {
        $this->lastSeenAt = $when;
    }
}
