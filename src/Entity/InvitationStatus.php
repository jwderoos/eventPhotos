<?php

declare(strict_types=1);

namespace App\Entity;

enum InvitationStatus: string
{
    case Pending = 'pending';
    case Used = 'used';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
