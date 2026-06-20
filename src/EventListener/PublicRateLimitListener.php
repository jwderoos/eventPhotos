<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Coarse per-client-IP rate limit on the anonymous public event routes (#23).
 *
 * Priority 20 places this after the RouterListener (32) — so `_route` is set —
 * and before the firewall (8), so floods are rejected before any auth work.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onRequest', priority: 20)]
final readonly class PublicRateLimitListener
{
    /**
     * Route names sharing one per-client-IP bucket. Photo-serve (high-volume
     * legit traffic, ~200 requests/page) and the lightbox neighbor endpoint
     * (cheap, not an enumeration vector) are deliberately absent — see
     * docs/superpowers/specs/2026-06-20-23-public-route-rate-limiting-design.md.
     *
     * @var list<string>
     */
    public const array LIMITED_ROUTES = [
        'public_event_landing',
        'public_event_photos',
        'public_event_display',
        'public_event_display_qr',
    ];

    public function __construct(
        private RateLimiterFactoryInterface $publicEventLimiter,
        private ClockInterface $clock,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route   = $request->attributes->get('_route');
        if (!is_string($route) || !in_array($route, self::LIMITED_ROUTES, true)) {
            return;
        }

        $clientIp = $request->getClientIp();
        if ($clientIp === null) {
            return;
        }

        $limit = $this->publicEventLimiter->create($clientIp)->consume();
        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp();
        throw new TooManyRequestsHttpException(max(1, $retryAfter));
    }
}
