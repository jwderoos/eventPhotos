<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BibSuppressionRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BibSuppressionRepository::class)]
#[ORM\Table(name: 'bib_suppressions')]
#[ORM\UniqueConstraint(name: 'uniq_bib_suppression_event_bib', columns: ['event_id', 'bib_number'])]
class BibSuppression
{
    public const int MAX_BIB_NUMBER_LENGTH = 64;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Event::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Event $event,
        #[ORM\Column(type: Types::STRING, length: self::MAX_BIB_NUMBER_LENGTH)]
        private string $bibNumber,
    ) {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function getBibNumber(): string
    {
        return $this->bibNumber;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
