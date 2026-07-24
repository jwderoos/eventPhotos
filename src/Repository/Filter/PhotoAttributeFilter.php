<?php

declare(strict_types=1);

namespace App\Repository\Filter;

final readonly class PhotoAttributeFilter
{
    /**
     * @param list<string> $colours
     * @param list<string> $garments
     * @param list<string> $scenes
     */
    public function __construct(
        public array $colours = [],
        public array $garments = [],
        public ?string $bib = null,
        public array $scenes = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->colours === []
            && $this->garments === []
            && $this->scenes === []
            && $this->bib === null;
    }
}
