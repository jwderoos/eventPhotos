<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Embeddable]
class PreviewSettings
{
    /** @var list<int> */
    public const array ALLOWED_LONG_EDGES = [1280, 1600, 2048, 2560];

    /** @var list<int> */
    public const array ALLOWED_QUALITIES = [70, 80, 85, 90];

    public const int DEFAULT_LONG_EDGE = 1600;

    public const int DEFAULT_QUALITY = 85;

    /** @var int<1, max> */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => self::DEFAULT_LONG_EDGE])]
    #[Assert\Choice(choices: self::ALLOWED_LONG_EDGES, message: 'Choose a supported display image size.')]
    private int $longEdge = self::DEFAULT_LONG_EDGE;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => self::DEFAULT_QUALITY])]
    #[Assert\Choice(choices: self::ALLOWED_QUALITIES, message: 'Choose a supported display image quality.')]
    private int $quality = self::DEFAULT_QUALITY;

    /** @return int<1, max> */
    public function getLongEdge(): int
    {
        return $this->longEdge;
    }

    /** @param int<1, max> $longEdge */
    public function setLongEdge(int $longEdge): void
    {
        $this->longEdge = $longEdge;
    }

    public function getQuality(): int
    {
        return $this->quality;
    }

    public function setQuality(int $quality): void
    {
        $this->quality = $quality;
    }
}
