<?php

declare(strict_types=1);

namespace App\Service\Invitation;

final readonly class GeneratedToken
{
    public function __construct(
        public string $plaintext,
        public string $selector,
        public string $hashedVerifier,
    ) {
    }
}
