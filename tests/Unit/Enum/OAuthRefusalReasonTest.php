<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\OAuthRefusalReason;
use PHPUnit\Framework\TestCase;

final class OAuthRefusalReasonTest extends TestCase
{
    public function testEveryCaseHasNonEmptyUserMessage(): void
    {
        foreach (OAuthRefusalReason::cases() as $reason) {
            $this->assertNotSame('', $reason->userMessage(), $reason->value);
        }
    }

    public function testValuesAreStableForLogging(): void
    {
        $this->assertSame('email_not_verified', OAuthRefusalReason::EmailNotVerified->value);
        $this->assertSame('no_account', OAuthRefusalReason::NoAccount->value);
        $this->assertSame('email_bound_to_other_google', OAuthRefusalReason::EmailBoundToOtherGoogle->value);
        $this->assertSame('already_linked_to_current', OAuthRefusalReason::AlreadyLinkedToCurrent->value);
        $this->assertSame('bound_to_other_user', OAuthRefusalReason::BoundToOtherUser->value);
        $this->assertSame('email_taken', OAuthRefusalReason::EmailTaken->value);
        $this->assertSame('invite_invalid', OAuthRefusalReason::InviteInvalid->value);
    }
}
