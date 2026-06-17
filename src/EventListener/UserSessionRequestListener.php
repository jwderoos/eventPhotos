<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\UserSession;
use App\Entity\User;
use App\Repository\UserSessionRepository;
use App\Service\Session\UserSessionCreator;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, method: 'onRequest', priority: -10)]
final readonly class UserSessionRequestListener
{
    private const int THROTTLE_SECONDS = 60;

    public function __construct(
        private Security $security,
        private UserSessionCreator $creator,
        private UserSessionRepository $repo,
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession(true)) {
            return;
        }

        $session = $request->getSession();
        if (!$session->isStarted()) {
            return;
        }

        $sessId = $session->getId();
        if ($sessId === '') {
            return;
        }

        $existing = $this->repo->findOneBySessId($sessId);

        if (!$existing instanceof UserSession) {
            $this->creator->create(
                sessId: $sessId,
                user: $user,
                ip: $request->getClientIp() ?? '0.0.0.0',
                userAgent: $request->headers->get('User-Agent', ''),
            );
            return;
        }

        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        if ($now->getTimestamp() - $existing->getLastSeenAt()->getTimestamp() < self::THROTTLE_SECONDS) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE user_sessions SET last_seen_at = :now WHERE sess_id = :sid',
            ['now' => $now->format('Y-m-d H:i:s'), 'sid' => $sessId],
        );
    }
}
