<?php

declare(strict_types=1);

namespace App\Repository\Filter;

final readonly class PhotoAttributeFilter
{
    /**
     * @param list<string> $colours
     * @param list<string> $garments
     */
    public function __construct(
        public array $colours = [],
        public array $garments = [],
        public ?string $bib = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->colours === [] && $this->garments === [] && $this->bib === null;
    }
}
