<?php

declare(strict_types=1);

namespace App\Service\Event\Archive;

use RuntimeException;

final class SlugAlreadyExistsException extends RuntimeException
{
    public function __construct(public readonly string $slug)
    {
        parent::__construct(sprintf('An event with slug "%s" already exists.', $slug));
    }
}
