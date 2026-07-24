<?php

declare(strict_types=1);

namespace App\Service\Photo;

interface AttributeExtractorClientInterface
{
    /**
     * @throws AttributeExtractionUnavailable when the inference service is unreachable or returns a non-200 response
     */
    public function extract(string $imageBytes): ExtractedAttributes;
}
