<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Enum\OAuthRefusalReason;
use RuntimeException;

final class LoginRefused extends RuntimeException
{
    public function __construct(public readonly OAuthRefusalReason $reason)
    {
        parent::__construct($reason->value);
    }
}
