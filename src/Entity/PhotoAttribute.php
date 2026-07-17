<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PhotoAttributeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PhotoAttributeRepository::class)]
#[ORM\Table(name: 'photo_attributes')]
#[ORM\Index(name: 'idx_photo_attributes_photo_type', columns: ['photo_id', 'type'])]
#[ORM\Index(name: 'idx_photo_attributes_type_value', columns: ['type', 'value'])]
class PhotoAttribute
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Photo::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Photo $photo,
        #[ORM\Column(type: Types::STRING, length: 32, enumType: PhotoAttributeType::class)]
        private PhotoAttributeType $type,
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $value,
        #[ORM\Column(type: Types::FLOAT, nullable: true)]
        private ?float $confidence = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPhoto(): Photo
    {
        return $this->photo;
    }

    public function getType(): PhotoAttributeType
    {
        return $this->type;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getConfidence(): ?float
    {
        return $this->confidence;
    }
}
