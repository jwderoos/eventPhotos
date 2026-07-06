<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrganizerProfileRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[ORM\Entity(repositoryClass: OrganizerProfileRepository::class)]
#[ORM\Table(name: 'organizer_profiles')]
#[ORM\UniqueConstraint(name: 'uniq_organizer_profiles_user', columns: ['user_id'])]
#[Vich\Uploadable]
class OrganizerProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Embedded(class: StyleSettings::class, columnPrefix: 'style_')]
    private StyleSettings $style;

    #[ORM\Column(type: Types::STRING, length: 120, nullable: true)]
    private ?string $brandLabel = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $brandLogoFilename = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $brandLogoUpdatedAt = null;

    #[ORM\Column(type: Types::STRING, length: 512, nullable: true)]
    #[Assert\Url(protocols: ['http', 'https'])]
    private ?string $brandUrl = null;

    #[Vich\UploadableField(mapping: 'brand_logo', fileNameProperty: 'brandLogoFilename')]
    #[Assert\File(
        maxSize: '2M',
        mimeTypes: ['image/png', 'image/jpeg'],
        mimeTypesMessage: 'Please upload a PNG or JPEG image.',
    )]
    private ?File $brandLogoFile = null;

    public function __construct(
        #[ORM\OneToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false)]
        private readonly User $user,
    ) {
        $this->style = new StyleSettings();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getStyle(): StyleSettings
    {
        return $this->style;
    }

    public function getBrandLabel(): ?string
    {
        return $this->brandLabel;
    }

    public function setBrandLabel(?string $brandLabel): void
    {
        $this->brandLabel = $brandLabel === '' ? null : $brandLabel;
    }

    public function getBrandLogoFilename(): ?string
    {
        return $this->brandLogoFilename;
    }

    public function setBrandLogoFilename(?string $brandLogoFilename): void
    {
        $this->brandLogoFilename = $brandLogoFilename;
    }

    public function getBrandLogoUpdatedAt(): ?DateTimeImmutable
    {
        return $this->brandLogoUpdatedAt;
    }

    public function getBrandLogoFile(): ?File
    {
        return $this->brandLogoFile;
    }

    public function setBrandLogoFile(?File $brandLogoFile): void
    {
        $this->brandLogoFile = $brandLogoFile;

        if ($brandLogoFile instanceof File) {
            $this->brandLogoUpdatedAt = new DateTimeImmutable();
        }
    }

    public function getBrandUrl(): ?string
    {
        return $this->brandUrl;
    }

    public function setBrandUrl(?string $brandUrl): void
    {
        $this->brandUrl = $brandUrl === '' ? null : $brandUrl;
    }

    public function hasBrand(): bool
    {
        return $this->brandLabel !== null || $this->brandLogoFilename !== null;
    }
}
