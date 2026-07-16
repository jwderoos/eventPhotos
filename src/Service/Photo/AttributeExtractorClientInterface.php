<?php

declare(strict_types=1);

namespace App\Service\Photo;

interface AttributeExtractorClientInterface
{
    public function extract(string $imageBytes): ExtractedAttributes;
}
