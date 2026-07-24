<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\EventNotificationStatus;
use App\Entity\UserMailConfig;
use App\Message\SendSubscriptionConfirmationEmail;
use App\Repository\EventNotificationSubscriptionRepository;
use App\Service\Mail\EventStyledEmailFactory;
use App\Service\Mail\OrganizerMailerResolver;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendSubscriptionConfirmationEmailHandler
{
    public function __construct(
        private EventNotificationSubscriptionRepository $subscriptions,
        private OrganizerMailerResolver $mailerResolver,
        private EventStyledEmailFactory $emailFactory,
    ) {
    }

    public function __invoke(SendSubscriptionConfirmationEmail $message): void
    {
        $subscription = $this->subscriptions->find($message->subscriptionId);
        if ($subscription === null || $subscription->getStatus() !== EventNotificationStatus::Pending) {
            return;
        }

        $event = $subscription->getEvent();
        $config = $event->getOwner()->getMailConfig();
        if (!$config instanceof UserMailConfig) {
            return;
        }

        // Strict resolver: a throw hard-fails into Messenger retry/dead-letter —
        // never a platform-mail fallback.
        $mailer = $this->mailerResolver->forEvent($event);
        $mailer->send($this->emailFactory->confirmation($event, $subscription, $config));
    }
}
