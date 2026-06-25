<?php

declare(strict_types=1);

namespace App\EventListener\Audit;

use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\BadgeInterface;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final readonly class AuthEventListener
{
    public function __construct(
        private AuditLogger $auditLogger,
        private RequestStack $requestStack,
    ) {
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        $actorId = $user instanceof User ? $user->getId() : null;
        $actorLabel = $user instanceof User ? $user->getEmail() : $user->getUserIdentifier();

        $this->auditLogger->log(
            AuditAction::AuthLoginSuccess,
            $actorId,
            $actorLabel,
            null,
            null,
            null,
            ['firewall' => $event->getFirewallName()],
            $this->clientIp(),
        );
    }

    #[AsEventListener(event: LoginFailureEvent::class)]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $attempted = null;
        $passport = $event->getPassport();
        if ($passport instanceof Passport && $passport->hasBadge(UserBadge::class)) {
            $badge = $passport->getBadge(UserBadge::class);
            if ($badge instanceof BadgeInterface) {
                $attempted = $badge->getUserIdentifier();
            }
        }

        $this->auditLogger->log(
            AuditAction::AuthLoginFailure,
            null,
            null,
            null,
            null,
            null,
            ['attempted_username' => $attempted, 'reason' => $event->getException()->getMessageKey()],
            $this->clientIp(),
        );
    }

    #[AsEventListener(event: LogoutEvent::class)]
    public function onLogout(LogoutEvent $event): void
    {
        $token = $event->getToken();
        $user = $token?->getUser();
        $actorId = $user instanceof User ? $user->getId() : null;
        $actorLabel = $user instanceof User ? $user->getEmail() : null;

        $this->auditLogger->log(
            AuditAction::AuthLogout,
            $actorId,
            $actorLabel,
            null,
            null,
            null,
            [],
            $this->clientIp(),
        );
    }

    private function clientIp(): ?string
    {
        return $this->requestStack->getCurrentRequest()?->getClientIp();
    }
}
