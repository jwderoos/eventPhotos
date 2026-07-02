<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Embeddable]
class StyleSettings
{
    public const string HEX_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    #[ORM\Column(type: Types::STRING, length: 7, nullable: true)]
    #[Assert\Regex(pattern: self::HEX_PATTERN, message: 'Use a #RRGGBB hex color.')]
    private ?string $fontColor = null;

    #[ORM\Column(type: Types::STRING, length: 7, nullable: true)]
    #[Assert\Regex(pattern: self::HEX_PATTERN, message: 'Use a #RRGGBB hex color.')]
    private ?string $backgroundColor = null;

    #[ORM\Column(type: Types::STRING, length: 7, nullable: true)]
    #[Assert\Regex(pattern: self::HEX_PATTERN, message: 'Use a #RRGGBB hex color.')]
    private ?string $buttonColor = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $glowEnabled = null;

    public function getFontColor(): ?string
    {
        return $this->fontColor;
    }

    public function setFontColor(?string $fontColor): void
    {
        $this->fontColor = $fontColor;
    }

    public function getBackgroundColor(): ?string
    {
        return $this->backgroundColor;
    }

    public function setBackgroundColor(?string $backgroundColor): void
    {
        $this->backgroundColor = $backgroundColor;
    }

    public function getButtonColor(): ?string
    {
        return $this->buttonColor;
    }

    public function setButtonColor(?string $buttonColor): void
    {
        $this->buttonColor = $buttonColor;
    }

    public function getGlowEnabled(): ?bool
    {
        return $this->glowEnabled;
    }

    public function setGlowEnabled(?bool $glowEnabled): void
    {
        $this->glowEnabled = $glowEnabled;
    }

    public function isEmpty(): bool
    {
        return $this->fontColor === null
            && $this->backgroundColor === null
            && $this->buttonColor === null
            && $this->glowEnabled === null;
    }
}
