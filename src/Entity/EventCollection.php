<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\StyleSettings;
use Stringable;
use App\Repository\EventCollectionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EventCollectionRepository::class)]
#[ORM\Table(name: 'event_collections')]
#[ORM\UniqueConstraint(name: 'uniq_event_collections_slug', columns: ['slug'])]
class EventCollection implements Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Embedded(class: StyleSettings::class, columnPrefix: 'style_')]
    private StyleSettings $style;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var Collection<int, Event> */
    #[ORM\OneToMany(targetEntity: Event::class, mappedBy: 'collection')]
    private Collection $events;

    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 120)]
        private string $slug,
        #[ORM\Column(type: Types::STRING, length: 200)]
        private string $name,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false)]
        private User $owner,
    ) {
        $this->events = new ArrayCollection();
        $this->style  = new StyleSettings();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStyle(): StyleSettings
    {
        return $this->style;
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

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): void
    {
        $this->owner = $owner;
    }

    /** @return Collection<int, Event> */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
