<?php

declare(strict_types=1);

namespace App\EventListener\Audit;

use App\Audit\Attribute\Audited;
use ReflectionMethod;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::CONTROLLER)]
final class AuditedControllerListener
{
    public const string REQUEST_ATTR = '_audited';

    private const int CALLABLE_ARRAY_LENGTH = 2;

    public function __invoke(ControllerEvent $event): void
    {
        $controller = $event->getController();

        if (!is_array($controller) || count($controller) !== self::CALLABLE_ARRAY_LENGTH) {
            return;
        }

        [$object, $method] = $controller;
        if (!is_object($object)) {
            return;
        }

        $reflection = new ReflectionMethod($object, $method);
        $attributes = $reflection->getAttributes(Audited::class);
        if ($attributes === []) {
            return;
        }

        $event->getRequest()->attributes->set(self::REQUEST_ATTR, $attributes[0]->newInstance());
    }
}
