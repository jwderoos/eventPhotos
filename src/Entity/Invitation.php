<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InvitationRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use DomainException;
use InvalidArgumentException;

#[ORM\Entity(repositoryClass: InvitationRepository::class)]
#[ORM\Table(name: 'invitations')]
#[ORM\UniqueConstraint(name: 'uniq_invitations_selector', columns: ['selector'])]
class Invitation
{
    public const array ALLOWED_ROLES = ['ROLE_ORGANIZER', 'ROLE_ADMIN'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $selector;

    #[ORM\Column(name: 'hashed_verifier', type: Types::STRING, length: 64)]
    private string $hashedVerifier;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $role;

    #[ORM\Column(type: Types::STRING, length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'used_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $usedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'used_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $usedBy = null;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'revoked_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $revokedBy = null;

    public function __construct(
        string $selector,
        string $hashedVerifier,
        string $role,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'created_by_id', nullable: false, onDelete: 'RESTRICT')]
        private User $createdBy,
        #[ORM\Column(name: 'expires_at', type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $expiresAt,
    ) {
        if ($selector === '') {
            throw new InvalidArgumentException('Invitation selector cannot be empty.');
        }

        if ($hashedVerifier === '') {
            throw new InvalidArgumentException('Invitation hashed verifier cannot be empty.');
        }

        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new InvalidArgumentException(sprintf('Invitation role "%s" is not allowed.', $role));
        }

        $this->selector = $selector;
        $this->hashedVerifier = $hashedVerifier;
        $this->role = $role;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSelector(): string
    {
        return $this->selector;
    }

    public function getHashedVerifier(): string
    {
        return $this->hashedVerifier;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function getUsedBy(): ?User
    {
        return $this->usedBy;
    }

    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getRevokedBy(): ?User
    {
        return $this->revokedBy;
    }

    public function status(): InvitationStatus
    {
        if ($this->usedAt instanceof DateTimeImmutable) {
            return InvitationStatus::Used;
        }

        if ($this->revokedAt instanceof DateTimeImmutable) {
            return InvitationStatus::Revoked;
        }

        if ($this->expiresAt < new DateTimeImmutable()) {
            return InvitationStatus::Expired;
        }

        return InvitationStatus::Pending;
    }

    public function isPending(): bool
    {
        return $this->status() === InvitationStatus::Pending;
    }

    public function markUsed(User $newUser, string $email): void
    {
        if (!$this->isPending()) {
            throw new DomainException(sprintf(
                'Cannot mark invitation as used from status %s.',
                $this->status()->value,
            ));
        }

        $this->usedAt = new DateTimeImmutable();
        $this->usedBy = $newUser;
        $this->email = $email;
    }

    public function revoke(User $admin): void
    {
        if (!$this->isPending()) {
            throw new DomainException(sprintf(
                'Cannot revoke invitation from status %s.',
                $this->status()->value,
            ));
        }

        $this->revokedAt = new DateTimeImmutable();
        $this->revokedBy = $admin;
    }
}
