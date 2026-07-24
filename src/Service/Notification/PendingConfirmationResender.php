<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Event;
use App\Message\SendSubscriptionConfirmationEmail;
use App\Repository\EventNotificationSubscriptionRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Shared re-send of the double-opt-in confirmation to an event's Pending
 * subscribers. Used by the organizer dashboard button (#125) and — later — the
 * console command in #123, so both share one send path.
 */
final readonly class PendingConfirmationResender
{
    private const int MS_PER_MINUTE = 60_000;

    public function __construct(
        private EventNotificationSubscriptionRepository $subscriptions,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
        #[Autowire('%env(int:EVENT_LIVE_NOTIFICATION_RATE_PER_MIN)%')]
        private int $ratePerMinute,
    ) {
    }

    /**
     * Re-send to every Pending subscriber of the event: a fresh token/expiry
     * (so stale links don't hit the timed-out page), then the async confirmation
     * message, spaced to respect the organizer's SMTP caps. Confirmed and
     * Unsubscribed rows are never touched.
     */
    public function resendAll(Event $event): int
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $pending = $this->subscriptions->findPendingByEvent($event);

        foreach ($pending as $subscription) {
            $subscription->restartPending($now);
        }

        $this->em->flush();

        $rate = max(1, $this->ratePerMinute);
        $intervalMs = intdiv(self::MS_PER_MINUTE, $rate);

        $index = 0;
        foreach ($pending as $subscription) {
            $this->bus->dispatch(
                new SendSubscriptionConfirmationEmail((int) $subscription->getId()),
                [new DelayStamp($index * $intervalMs)],
            );
            $this->logger->info('Re-queued confirmation for pending subscriber.', [
                'event_id' => $event->getId(),
                'subscription_id' => $subscription->getId(),
            ]);
            ++$index;
        }

        return count($pending);
    }
}
