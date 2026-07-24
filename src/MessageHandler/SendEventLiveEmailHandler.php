<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\EventNotificationStatus;
use App\Entity\UserMailConfig;
use App\Message\SendEventLiveEmail;
use App\Repository\EventNotificationSubscriptionRepository;
use App\Service\Mail\EventStyledEmailFactory;
use App\Service\Mail\OrganizerMailerResolver;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final readonly class SendEventLiveEmailHandler
{
    public function __construct(
        private EventNotificationSubscriptionRepository $subscriptions,
        private OrganizerMailerResolver $mailerResolver,
        private UrlGeneratorInterface $urlGenerator,
        private EntityManagerInterface $em,
        private EventStyledEmailFactory $styledEmailFactory,
    ) {
    }

    public function __invoke(SendEventLiveEmail $message): void
    {
        $subscription = $this->subscriptions->find($message->subscriptionId);
        if (
            $subscription === null
            || $subscription->getStatus() !== EventNotificationStatus::Confirmed
            || $subscription->getNotifiedAt() !== null
        ) {
            return;
        }

        $event = $subscription->getEvent();
        $config = $event->getOwner()->getMailConfig();
        if (!$config instanceof UserMailConfig) {
            return;
        }

        // Strict resolver: throws if the organizer transport vanished. The thrown
        // exception hard-fails the message into Messenger's retry/dead-letter path —
        // never a platform-mail fallback.
        $mailer = $this->mailerResolver->forEvent($event);

        $eventUrl = $this->urlGenerator->generate(
            'public_event_landing',
            ['slug' => $event->getSlug()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $unsubscribeUrl = $this->urlGenerator->generate(
            'public_event_notify_unsubscribe',
            ['slug' => $event->getSlug(), 'token' => $subscription->getUnsubscribeToken()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = $this->styledEmailFactory->create(
            $event,
            'email/event-notification/live.html.twig',
            'email/event-notification/live.txt.twig',
            [
                'eventName' => $event->getName(),
                'eventUrl' => $eventUrl,
                'unsubscribeUrl' => $unsubscribeUrl,
            ],
        )
            ->from($config->getSenderAddress())
            ->to($subscription->getEmail())
            ->subject(sprintf('Photos from %s are live', $event->getName()));

        $mailer->send($email);

        $subscription->markNotified(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $this->em->flush();
    }
}
