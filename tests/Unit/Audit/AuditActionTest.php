<?php

declare(strict_types=1);

namespace App\Tests\Unit\Audit;

use App\Audit\AuditAction;
use PHPUnit\Framework\TestCase;

final class AuditActionTest extends TestCase
{
    public function testBackingValuesAreStable(): void
    {
        $this->assertSame('event.delete', AuditAction::EventDelete->value);
        $this->assertSame('auth.login_failure', AuditAction::AuthLoginFailure->value);
        $this->assertSame('user.role_change', AuditAction::UserRoleChange->value);
    }

    public function testEveryCaseHasNonEmptyLabelAndCategory(): void
    {
        foreach (AuditAction::cases() as $action) {
            $this->assertNotSame('', $action->label(), $action->name . ' label');
            $this->assertNotSame('', $action->category(), $action->name . ' category');
        }
    }

    public function testCategoryGroupsByPrefix(): void
    {
        $this->assertSame('Event', AuditAction::EventCreate->category());
        $this->assertSame('Authentication', AuditAction::AuthLogout->category());
    }
}
