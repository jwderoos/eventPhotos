<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PhotoRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity(repositoryClass: PhotoRepository::class)]
#[ORM\Table(name: 'photos')]
#[ORM\UniqueConstraint(name: 'uniq_photos_event_hash', columns: ['event_id', 'content_hash'])]
#[ORM\Index(name: 'idx_photos_event_status_taken_at', columns: ['event_id', 'status', 'taken_at'])]
#[ORM\HasLifecycleCallbacks]
class Photo
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $width = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $height = null;

    /**
     * Total bytes of stored derivatives (thumb + preview).
     *
     * Null for photos ingested before #55 — those still have byteSize (the
     * original upload size) as their best available size figure.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $derivativeBytes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $takenAt = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: PhotoStatus::class)]
    private PhotoStatus $status = PhotoStatus::Pending;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $processingError = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Event::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Event $event,
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $contentHash,
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $originalFilename,
        #[ORM\Column(type: Types::INTEGER)]
        private int $byteSize,
    ) {
        $now = new DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function getByteSize(): int
    {
        return $this->byteSize;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function getDerivativeBytes(): ?int
    {
        return $this->derivativeBytes;
    }

    public function getTakenAt(): ?DateTimeImmutable
    {
        return $this->takenAt;
    }

    public function getStatus(): PhotoStatus
    {
        return $this->status;
    }

    public function getProcessingError(): ?string
    {
        return $this->processingError;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function markReady(DateTimeImmutable $takenAt, int $width, int $height, int $derivativeBytes): void
    {
        if ($this->status !== PhotoStatus::Pending) {
            throw new DomainException(sprintf(
                'Photo %d cannot transition from %s to ready.',
                (int) $this->id,
                $this->status->value,
            ));
        }

        $this->takenAt = $takenAt;

        $this->width = $width;
        $this->height = $height;
        $this->derivativeBytes = $derivativeBytes;
        $this->processingError = null;
        $this->status = PhotoStatus::Ready;
    }

    public function markFailed(string $reason): void
    {
        if ($this->status === PhotoStatus::Ready) {
            throw new DomainException(sprintf(
                'Photo %d is ready; refusing to mark failed.',
                (int) $this->id,
            ));
        }

        $this->processingError = $reason;
        $this->status = PhotoStatus::Failed;
    }

    public function resetForRetry(): void
    {
        if ($this->status !== PhotoStatus::Failed) {
            throw new DomainException(sprintf(
                'Photo %d cannot be reset for retry from %s.',
                (int) $this->id,
                $this->status->value,
            ));
        }

        $this->processingError = null;
        $this->status = PhotoStatus::Pending;
    }

    public function resetForReingest(): void
    {
        if ($this->status !== PhotoStatus::Ready) {
            throw new DomainException(sprintf(
                'Photo %d cannot be reset for re-ingest from %s.',
                (int) $this->id,
                $this->status->value,
            ));
        }

        $this->processingError = null;
        $this->status = PhotoStatus::Pending;
    }
}
