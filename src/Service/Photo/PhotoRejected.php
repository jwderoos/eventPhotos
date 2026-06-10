<?php

declare(strict_types=1);

namespace App\Service\Photo;

use RuntimeException;

/**
 * Thrown when a photo cannot be processed for a reason that won't be
 * fixed by retrying (e.g., missing EXIF DateTimeOriginal). Caller is
 * expected to catch and call Photo::markFailed().
 */
final class PhotoRejected extends RuntimeException
{
}
