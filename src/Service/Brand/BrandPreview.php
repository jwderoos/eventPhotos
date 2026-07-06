<?php

declare(strict_types=1);

namespace App\Service\Brand;

final readonly class BrandPreview
{
    public function __construct(
        public ?string $label,
        public ?string $logoUrl,
    ) {
    }
}
