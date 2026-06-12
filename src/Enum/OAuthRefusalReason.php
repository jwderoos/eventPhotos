<?php

declare(strict_types=1);

namespace App\Enum;

enum OAuthRefusalReason: string
{
    case EmailNotVerified = 'email_not_verified';
    case NoAccount = 'no_account';
    case EmailBoundToOtherGoogle = 'email_bound_to_other_google';
    case AlreadyLinkedToCurrent = 'already_linked_to_current';
    case BoundToOtherUser = 'bound_to_other_user';
    case EmailTaken = 'email_taken';
    case InviteInvalid = 'invite_invalid';

    public function userMessage(): string
    {
        return match ($this) {
            self::EmailNotVerified => 'Your Google account email is not verified. Verify it with Google and try again.',
            self::NoAccount => 'No account found for this Google email. Ask an organizer for an invite.',
            self::EmailBoundToOtherGoogle => 'Sign in with your password and link Google from your account page.',
            self::AlreadyLinkedToCurrent => 'A Google account is already linked to this user.',
            self::BoundToOtherUser => 'This Google account is already linked to a different user.',
            self::EmailTaken => 'An account already exists for this email — sign in or reset your password.',
            self::InviteInvalid => 'This invitation is no longer valid.',
        };
    }
}
