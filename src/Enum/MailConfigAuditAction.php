<?php

declare(strict_types=1);

namespace App\Enum;

enum MailConfigAuditAction: string
{
    case Set = 'set';
    case Verified = 'verified';
    case Cleared = 'cleared';
    case VerificationResent = 'verification_resent';
}
