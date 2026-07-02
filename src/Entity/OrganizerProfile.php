<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrganizerProfileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrganizerProfileRepository::class)]
#[ORM\Table(name: 'organizer_profiles')]
#[ORM\UniqueConstraint(name: 'uniq_organizer_profiles_user', columns: ['user_id'])]
class OrganizerProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Embedded(class: StyleSettings::class, columnPrefix: 'style_')]
    private StyleSettings $style;

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
}
