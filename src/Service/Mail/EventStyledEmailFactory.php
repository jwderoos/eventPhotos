<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Event;
use App\Entity\EventNotificationSubscription;
use App\Entity\UserMailConfig;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Central construction point for the visitor-notification emails (double-opt-in
 * confirmation + "photos are live" announcement). Callers guard the null mail
 * config and pass a non-null {@see UserMailConfig} so this stays pure.
 */
final readonly class EventStyledEmailFactory
{
    public function __construct(private UrlGeneratorInterface $urlGenerator)
    {
    }

    public function confirmation(
        Event $event,
        EventNotificationSubscription $subscription,
        UserMailConfig $config,
    ): TemplatedEmail {
        $confirmUrl = $this->urlGenerator->generate('public_event_notify_confirm', [
            'slug' => $event->getSlug(),
            'token' => $subscription->getConfirmationToken(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        return new TemplatedEmail()
            ->from($config->getSenderAddress())
            ->to($subscription->getEmail())
            ->subject(sprintf('Confirm notifications for %s', $event->getName()))
            ->htmlTemplate('email/event-notification/confirm.html.twig')
            ->textTemplate('email/event-notification/confirm.txt.twig')
            ->context([
                'eventName' => $event->getName(),
                'confirmUrl' => $confirmUrl,
                'unsubscribeUrl' => $this->unsubscribeUrl($event, $subscription),
            ]);
    }

    public function liveAnnouncement(
        Event $event,
        EventNotificationSubscription $subscription,
        UserMailConfig $config,
    ): TemplatedEmail {
        $eventUrl = $this->urlGenerator->generate('public_event_landing', [
            'slug' => $event->getSlug(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        return new TemplatedEmail()
            ->from($config->getSenderAddress())
            ->to($subscription->getEmail())
            ->subject(sprintf('Photos from %s are live', $event->getName()))
            ->htmlTemplate('email/event-notification/live.html.twig')
            ->textTemplate('email/event-notification/live.txt.twig')
            ->context([
                'eventName' => $event->getName(),
                'eventUrl' => $eventUrl,
                'unsubscribeUrl' => $this->unsubscribeUrl($event, $subscription),
            ]);
    }

    private function unsubscribeUrl(Event $event, EventNotificationSubscription $subscription): string
    {
        return $this->urlGenerator->generate('public_event_notify_unsubscribe', [
            'slug' => $event->getSlug(),
            'token' => $subscription->getUnsubscribeToken(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
