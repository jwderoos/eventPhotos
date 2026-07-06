<?php

declare(strict_types=1);

namespace App\Service\Brand;

final readonly class ResolvedBrand
{
    public function __construct(
        public ?string $label,
        public bool $hasLogo,
        public ?string $url,
    ) {
    }
}
