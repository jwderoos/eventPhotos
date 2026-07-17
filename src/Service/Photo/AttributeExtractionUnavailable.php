<?php

declare(strict_types=1);

namespace App\Service\Photo;

use RuntimeException;

/**
 * Thrown when the attribute-extraction inference service cannot be reached
 * or returns a non-200 response. Callers must NOT treat this as "found no
 * attributes" — doing so would delete a photo's existing tags and mark it
 * as tagged despite the extraction never actually running (see #117).
 */
final class AttributeExtractionUnavailable extends RuntimeException
{
}
