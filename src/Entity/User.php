<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AuthProvider;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_users_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING)]
    private string $password = '';

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    /** @var Collection<int, UserIdentity> */
    #[ORM\OneToMany(
        targetEntity: UserIdentity::class,
        mappedBy: 'user',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $identities;

    #[ORM\OneToOne(mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?UserMailConfig $mailConfig = null;

    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 180)]
        private string $email,
        #[ORM\Column(type: Types::STRING, length: 120)]
        private string $displayName,
    ) {
        if ($email === '') {
            throw new InvalidArgumentException('User email cannot be empty.');
        }

        $this->identities = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** @return non-empty-string */
    public function getEmail(): string
    {
        if ($this->email === '') {
            throw new InvalidArgumentException('User email cannot be empty.');
        }

        return $this->email;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): void
    {
        $this->displayName = $displayName;
    }

    public function getUserIdentifier(): string
    {
        return $this->getEmail();
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashed): void
    {
        $this->password = $hashed;
    }

    public function hasUsablePassword(): bool
    {
        return $this->password !== '';
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_values(array_unique($roles));
    }

    public function addRole(string $role): void
    {
        if (!in_array($role, $this->roles, true)) {
            $this->roles[] = $role;
        }
    }

    public function removeRole(string $role): void
    {
        $this->roles = array_values(array_filter($this->roles, static fn (string $r): bool => $r !== $role));
    }

    /** @return Collection<int, UserIdentity> */
    public function getIdentities(): Collection
    {
        return $this->identities;
    }

    public function addIdentity(UserIdentity $identity): void
    {
        if (!$this->identities->contains($identity)) {
            $this->identities->add($identity);
        }
    }

    public function removeIdentity(UserIdentity $identity): void
    {
        $this->identities->removeElement($identity);
    }

    public function hasIdentityFor(AuthProvider $provider): bool
    {
        return $this->getIdentityFor($provider) instanceof UserIdentity;
    }

    public function getIdentityFor(AuthProvider $provider): ?UserIdentity
    {
        foreach ($this->identities as $identity) {
            if ($identity->getProvider() === $provider) {
                return $identity;
            }
        }

        return null;
    }

    public function getMailConfig(): ?UserMailConfig
    {
        return $this->mailConfig;
    }

    public function setMailConfig(?UserMailConfig $mailConfig): void
    {
        $this->mailConfig = $mailConfig;
    }
}
