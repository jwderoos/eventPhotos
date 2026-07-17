<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Service\Photo\AttributeExtractorClientInterface;
use App\Service\Photo\ExtractedAttributes;

/**
 * Test-only fake. Tests configure the next response via setNext();
 * defaults to an empty extraction. Mirrors FakeGoogleOAuthClient's shape.
 */
final class FakeAttributeExtractorClient implements AttributeExtractorClientInterface
{
    public string $lastImageBytes = '';

    private ?ExtractedAttributes $next = null;

    public function setNext(ExtractedAttributes $attributes): void
    {
        $this->next = $attributes;
    }

    public function extract(string $imageBytes): ExtractedAttributes
    {
        $this->lastImageBytes = $imageBytes;

        return $this->next ?? ExtractedAttributes::empty();
    }
}
