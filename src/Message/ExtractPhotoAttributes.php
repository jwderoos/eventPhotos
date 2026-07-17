<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ExtractPhotoAttributes
{
    public function __construct(public int $photoId)
    {
    }
}
