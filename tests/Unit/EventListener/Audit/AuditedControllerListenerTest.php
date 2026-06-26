<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener\Audit;

use App\Audit\AuditAction;
use App\Audit\Attribute\Audited;
use App\EventListener\Audit\AuditedControllerListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class AuditedControllerListenerTest extends TestCase
{
    public function testStashesAuditedAttributeWhenPresent(): void
    {
        $controller = new class {
            #[Audited(AuditAction::EventDelete, targetParam: 'id', targetType: 'Event')]
            public function delete(): Response
            {
                return new Response();
            }
        };

        $request = new Request();
        $event = new ControllerEvent(
            $this->createStub(HttpKernelInterface::class),
            [$controller, 'delete'],
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        (new AuditedControllerListener())($event);

        $stashed = $request->attributes->get(AuditedControllerListener::REQUEST_ATTR);
        $this->assertInstanceOf(Audited::class, $stashed);
        $this->assertSame(AuditAction::EventDelete, $stashed->action);
        $this->assertSame('id', $stashed->targetParam);
        $this->assertSame('Event', $stashed->targetType);
    }

    public function testIgnoresControllerWithoutAttribute(): void
    {
        $controller = new class {
            public function plain(): Response
            {
                return new Response();
            }
        };

        $request = new Request();
        $event = new ControllerEvent(
            $this->createStub(HttpKernelInterface::class),
            [$controller, 'plain'],
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        (new AuditedControllerListener())($event);

        $this->assertFalse($request->attributes->has(AuditedControllerListener::REQUEST_ATTR));
    }
}
