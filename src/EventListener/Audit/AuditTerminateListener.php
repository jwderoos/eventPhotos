<?php

declare(strict_types=1);

namespace App\EventListener\Audit;

use App\Audit\AuditContext;
use App\Audit\AuditLogger;
use App\Audit\Attribute\Audited;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::TERMINATE)]
final readonly class AuditTerminateListener
{
    public function __construct(
        private AuditLogger $auditLogger,
        private AuditContext $context,
        private Security $security,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $request = $event->getRequest();

        $audited = $request->attributes->get(AuditedControllerListener::REQUEST_ATTR);
        if (!$audited instanceof Audited) {
            return;
        }

        if ($this->context->isSuppressedOnRequest($request)) {
            return;
        }

        // Success convention: post/redirect/get. Failures throw (403/422/5xx) — not 3xx.
        if (!$event->getResponse()->isRedirection()) {
            return;
        }

        $user = $this->security->getUser();
        $actorId = $user instanceof User ? $user->getId() : null;
        $actorLabel = $user instanceof User ? $user->getEmail() : null;

        $targetId = null;
        if ($audited->targetParam !== null) {
            $raw = $request->attributes->get($audited->targetParam);
            $targetId = is_numeric($raw) ? (int) $raw : null;
        }

        $action = $this->context->overriddenActionOnRequest($request) ?? $audited->action;

        $this->auditLogger->log(
            $action,
            $actorId,
            $actorLabel,
            $audited->targetType,
            $targetId,
            $this->context->pulledTargetLabelFromRequest($request),
            $this->context->pullFromRequest($request),
            $request->getClientIp(),
        );
    }
}
