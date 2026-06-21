<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendEventLiveEmail;
use App\Message\SendEventLiveNotifications;
use App\Repository\EventNotificationSubscriptionRepository;
use App\Repository\EventRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
final readonly class SendEventLiveNotificationsHandler
{
    private const int MS_PER_MINUTE = 60_000;

    public function __construct(
        private EventRepository $events,
        private EventNotificationSubscriptionRepository $subscriptions,
        private MessageBusInterface $bus,
        #[Autowire('%env(int:EVENT_LIVE_NOTIFICATION_RATE_PER_MIN)%')]
        private int $ratePerMinute,
    ) {
    }

    public function __invoke(SendEventLiveNotifications $message): void
    {
        $event = $this->events->find($message->eventId);
        if ($event === null || !$event->isPublished()) {
            return;
        }

        $rate = max(1, $this->ratePerMinute);
        $intervalMs = intdiv(self::MS_PER_MINUTE, $rate);

        $index = 0;
        foreach ($this->subscriptions->findConfirmedByEvent($event) as $subscription) {
            $this->bus->dispatch(
                new SendEventLiveEmail((int) $subscription->getId()),
                [new DelayStamp($index * $intervalMs)],
            );
            ++$index;
        }
    }
}
