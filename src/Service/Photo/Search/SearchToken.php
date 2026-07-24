<?php

declare(strict_types=1);

namespace App\Service\Photo\Search;

use App\Entity\PhotoAttributeType;

final readonly class SearchToken
{
    /**
     * @param list<string> $canonicals resolved vocabulary values (or [bibNumber] for a Bib token)
     */
    public function __construct(
        public PhotoAttributeType $type,
        public string $sourceText,
        public array $canonicals,
        public string $label,
    ) {
    }
}
