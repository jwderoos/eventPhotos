<?php

declare(strict_types=1);

namespace App\Tests\Unit\Audit;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuditContextTest extends TestCase
{
    private function contextWithRequest(): AuditContext
    {
        $stack = new RequestStack();
        $stack->push(new Request());

        return new AuditContext($stack);
    }

    public function testAccumulatesSetChangedSnapshotAndLabel(): void
    {
        $ctx = $this->contextWithRequest();
        $ctx->set('reason', 'cleanup');
        $ctx->changed('role', 'ROLE_USER', 'ROLE_ORGANIZER');
        $ctx->snapshot(['name' => 'Hike 2026']);
        $ctx->targetLabel('Hike 2026');

        $this->assertFalse($ctx->isSuppressed());
        $this->assertSame('Hike 2026', $ctx->pulledTargetLabel());
        $this->assertSame([
            'reason' => 'cleanup',
            'changes' => ['role' => ['ROLE_USER', 'ROLE_ORGANIZER']],
            'snapshot' => ['name' => 'Hike 2026'],
        ], $ctx->pull());
    }

    public function testSuppress(): void
    {
        $ctx = $this->contextWithRequest();
        $ctx->suppress();
        $this->assertTrue($ctx->isSuppressed());
    }

    public function testNoCurrentRequestIsSafe(): void
    {
        $ctx = new AuditContext(new RequestStack());
        $ctx->set('x', 1);
        $ctx->suppress();

        $this->assertFalse($ctx->isSuppressed());
        $this->assertSame([], $ctx->pull());
        $this->assertNull($ctx->pulledTargetLabel());
    }

    public function testOverrideActionRoundTrip(): void
    {
        $stack = new RequestStack();
        $request = new Request();
        $stack->push($request);
        $ctx = new AuditContext($stack);

        $this->assertNotInstanceOf(AuditAction::class, $ctx->overriddenAction(), 'No override set yet');
        $this->assertNotInstanceOf(
            AuditAction::class,
            $ctx->overriddenActionOnRequest($request),
            'No override on request yet',
        );

        $ctx->overrideAction(AuditAction::UserRoleChange);

        $this->assertSame(AuditAction::UserRoleChange, $ctx->overriddenAction());
        $this->assertSame(AuditAction::UserRoleChange, $ctx->overriddenActionOnRequest($request));
    }

    public function testOverrideActionNoOpWhenNoRequest(): void
    {
        $ctx = new AuditContext(new RequestStack());
        // Must not throw; override is silently ignored when there is no request.
        $ctx->overrideAction(AuditAction::UserRoleChange);

        $this->assertNotInstanceOf(AuditAction::class, $ctx->overriddenAction());
    }

    public function testOverriddenActionOnRequestReturnsNullWhenNotSet(): void
    {
        $ctx = new AuditContext(new RequestStack());
        $request = new Request();

        $this->assertNotInstanceOf(AuditAction::class, $ctx->overriddenActionOnRequest($request));
    }
}
