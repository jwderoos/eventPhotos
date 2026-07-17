<?php

declare(strict_types=1);

namespace App\Service\Photo;

final readonly class AttributeScore
{
    public function __construct(
        public string $value,
        public float $confidence,
    ) {
    }
}
