<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ProcessPhoto
{
    public function __construct(public int $photoId)
    {
    }
}
