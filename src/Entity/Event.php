<?php

declare(strict_types=1);

namespace App\Entity;

use Stringable;
use App\Repository\EventRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'events')]
#[ORM\UniqueConstraint(name: 'uniq_events_slug', columns: ['slug'])]
#[Vich\Uploadable]
class Event implements Stringable
{
    public const int WINDOW_BEFORE_MINUTES = 10;

    public const int WINDOW_AFTER_MINUTES = 5;

    public const int MAX_WINDOW_MINUTES = 1440;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::STRING, length: 64)]
    #[Assert\Timezone]
    private string $timezone = 'Europe/Amsterdam';

    #[ORM\ManyToOne(targetEntity: EventCollection::class, inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: true)]
    private ?EventCollection $collection = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $logoFilename = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $logoUpdatedAt = null;

    #[Vich\UploadableField(mapping: 'event_logo', fileNameProperty: 'logoFilename')]
    #[Assert\File(
        maxSize: '2M',
        mimeTypes: ['image/png', 'image/jpeg'],
        mimeTypesMessage: 'Please upload a PNG or JPEG image.',
    )]
    private ?File $logoFile = null;

    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 120)]
        private string $slug,
        #[ORM\Column(type: Types::STRING, length: 200)]
        private string $name,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $startsAt,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $endsAt,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false)]
        private User $owner,
    ) {
        // Doctrine's datetime_immutable type writes via $value->format('Y-m-d H:i:s')
        // (using the object's tz) but loads with PHP's default tz (UTC here). Storing in
        // any non-UTC tz therefore corrupts the wall clock on the next hydration. Pin
        // both timestamps to UTC at the entity boundary so the round-trip is stable.
        $this->startsAt = $startsAt->setTimezone(new DateTimeZone('UTC'));
        $this->endsAt   = $endsAt->setTimezone(new DateTimeZone('UTC'));
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

    public function getStartsAt(): DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(DateTimeImmutable $startsAt): void
    {
        $this->startsAt = $startsAt->setTimezone(new DateTimeZone('UTC'));
    }

    public function getEndsAt(): DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(DateTimeImmutable $endsAt): void
    {
        $this->endsAt = $endsAt->setTimezone(new DateTimeZone('UTC'));
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): void
    {
        $this->timezone = $timezone;
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

    public function computeDisplayState(DateTimeImmutable $now): EventDisplayState
    {
        if ($now < $this->startsAt) {
            return EventDisplayState::Pre;
        }

        if ($now > $this->endsAt) {
            return EventDisplayState::Post;
        }

        return EventDisplayState::Live;
    }

    #[Assert\Callback]
    public function assertValidWindow(ExecutionContextInterface $context): void
    {
        if ($this->endsAt <= $this->startsAt) {
            $context->buildViolation('End must be strictly after start.')
                ->atPath('endsAt')
                ->addViolation();

            return;
        }

        $diffMinutes = (int) floor(
            ($this->endsAt->getTimestamp() - $this->startsAt->getTimestamp()) / 60
        );
        if ($diffMinutes > self::MAX_WINDOW_MINUTES) {
            $context->buildViolation('Event window cannot exceed 24 hours.')
                ->atPath('endsAt')
                ->addViolation();
        }
    }

    public function getLogoFilename(): ?string
    {
        return $this->logoFilename;
    }

    public function setLogoFilename(?string $logoFilename): void
    {
        $this->logoFilename = $logoFilename;
    }

    public function getLogoUpdatedAt(): ?DateTimeImmutable
    {
        return $this->logoUpdatedAt;
    }

    public function getLogoFile(): ?File
    {
        return $this->logoFile;
    }

    public function setLogoFile(?File $logoFile): void
    {
        $this->logoFile = $logoFile;

        if ($logoFile instanceof File) {
            $this->logoUpdatedAt = new DateTimeImmutable();
        }
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
