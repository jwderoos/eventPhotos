<?php

declare(strict_types=1);

namespace App\Service\Photo;

final readonly class ExtractedAttributes
{
    /**
     * @param list<AttributeScore> $clothingColors
     * @param list<AttributeScore> $clothingTypes
     * @param list<AttributeScore> $scenes
     * @param list<AttributeScore> $bibs
     */
    public function __construct(
        public array $clothingColors,
        public array $clothingTypes,
        public array $scenes,
        public array $bibs,
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], [], []);
    }
}
