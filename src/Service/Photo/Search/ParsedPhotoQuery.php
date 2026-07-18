<?php

declare(strict_types=1);

namespace App\Service\Photo\Search;

use App\Entity\PhotoAttributeType;
use App\Repository\Filter\PhotoAttributeFilter;

final readonly class ParsedPhotoQuery
{
    /**
     * @param list<SearchToken> $tokens
     * @param list<string>      $ignored
     */
    public function __construct(
        public array $tokens,
        public array $ignored,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->tokens === [];
    }

    public function toFilter(): PhotoAttributeFilter
    {
        $bib      = null;
        $colours  = [];
        $garments = [];
        $scenes   = [];

        foreach ($this->tokens as $token) {
            match ($token->type) {
                PhotoAttributeType::Bib => $bib ??= $token->canonicals[0] ?? null,
                PhotoAttributeType::ClothingColor => $colours = [...$colours, ...$token->canonicals],
                PhotoAttributeType::ClothingType => $garments = [...$garments, ...$token->canonicals],
                PhotoAttributeType::Scene => $scenes = [...$scenes, ...$token->canonicals],
            };
        }

        return new PhotoAttributeFilter(
            colours: array_values(array_unique($colours)),
            garments: array_values(array_unique($garments)),
            bib: $bib,
            scenes: array_values(array_unique($scenes)),
        );
    }

    public function without(int $index): self
    {
        $tokens = $this->tokens;
        unset($tokens[$index]);

        return new self(array_values($tokens), $this->ignored);
    }

    public function serialize(): string
    {
        $parts = array_map(static fn (SearchToken $t): string => $t->sourceText, $this->tokens);

        return trim(implode(' ', [...$parts, ...$this->ignored]));
    }
}
