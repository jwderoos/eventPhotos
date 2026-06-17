<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\Session\UserSessionCreator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;

#[AsEventListener(event: InteractiveLoginEvent::class, method: 'onLogin')]
final readonly class UserSessionLoginListener
{
    public function __construct(
        private UserSessionCreator $creator,
    ) {
    }

    public function onLogin(InteractiveLoginEvent $event): void
    {
        $user = $event->getAuthenticationToken()->getUser();
        if (!$user instanceof User) {
            return;
        }

        $request = $event->getRequest();
        $session = $request->getSession();

        $this->creator->create(
            sessId: $session->getId(),
            user: $user,
            ip: $request->getClientIp() ?? '0.0.0.0',
            userAgent: $request->headers->get('User-Agent', ''),
        );
    }
}
