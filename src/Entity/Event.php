<?php

declare(strict_types=1);

namespace App\Entity;

use Stringable;
use App\Repository\EventRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'events')]
#[ORM\UniqueConstraint(name: 'uniq_events_slug', columns: ['slug'])]
class Event implements Stringable
{
    public const int DEFAULT_WINDOW_MINUTES = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $startsAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $endsAt = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $defaultWindowMinutes = null;

    #[ORM\ManyToOne(targetEntity: EventCollection::class, inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: true)]
    private ?EventCollection $collection = null;

    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 120)]
        private string $slug,
        #[ORM\Column(type: Types::STRING, length: 200)]
        private string $name,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private DateTimeImmutable $date,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false)]
        private User $owner,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getDate(): DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(DateTimeImmutable $date): void
    {
        $this->date = $date;
    }

    public function getStartsAt(): ?DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(?DateTimeImmutable $startsAt): void
    {
        $this->startsAt = $startsAt;
    }

    public function getEndsAt(): ?DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(?DateTimeImmutable $endsAt): void
    {
        $this->endsAt = $endsAt;
    }

    public function getDefaultWindowMinutes(): ?int
    {
        return $this->defaultWindowMinutes;
    }

    public function setDefaultWindowMinutes(?int $minutes): void
    {
        $this->defaultWindowMinutes = $minutes;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): void
    {
        $this->owner = $owner;
    }

    public function getCollection(): ?EventCollection
    {
        return $this->collection;
    }

    public function setCollection(?EventCollection $collection): void
    {
        $this->collection = $collection;
    }

    public function resolveWindowMinutes(): int
    {
        return $this->defaultWindowMinutes ?? self::DEFAULT_WINDOW_MINUTES;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
