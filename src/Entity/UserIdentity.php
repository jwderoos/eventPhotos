<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AuthProvider;
use App\Repository\UserIdentityRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserIdentityRepository::class)]
#[ORM\Table(name: 'user_identities')]
#[ORM\UniqueConstraint(name: 'uniq_user_identity_provider_subject', columns: ['provider', 'subject'])]
#[ORM\UniqueConstraint(name: 'uniq_user_identity_user_provider', columns: ['user_id', 'provider'])]
class UserIdentity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'linked_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $linkedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'identities')]
        #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(type: Types::STRING, length: 32, enumType: AuthProvider::class)]
        private AuthProvider $provider,
        #[ORM\Column(type: Types::STRING, length: 191)]
        private string $subject,
        #[ORM\Column(type: Types::STRING, length: 180, nullable: true)]
        private ?string $email = null,
    ) {
        $this->linkedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getProvider(): AuthProvider
    {
        return $this->provider;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getLinkedAt(): DateTimeImmutable
    {
        return $this->linkedAt;
    }
}
