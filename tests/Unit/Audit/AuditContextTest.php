<?php

declare(strict_types=1);

namespace App\Tests\Unit\Audit;

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
}
