<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;

final readonly class LoginResult
{
    public function __construct(
        public User $user,
        public bool $wasAutoLinked,
    ) {
    }
}
