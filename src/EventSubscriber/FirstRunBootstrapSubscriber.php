<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Repository\UserRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class FirstRunBootstrapSubscriber implements EventSubscriberInterface
{
    private const int LISTENER_PRIORITY = 32;

    public function __construct(private UserRepository $users)
    {
    }

    /** @return array<string, array{string, int}> */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', self::LISTENER_PRIORITY]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if ($path === '/setup' || str_starts_with($path, '/_')) {
            return;
        }

        if ($this->users->count([]) > 0) {
            return;
        }

        $event->setResponse(new RedirectResponse('/setup'));
    }
}
