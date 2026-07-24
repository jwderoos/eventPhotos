<?php

declare(strict_types=1);

namespace App\Controller\Public;

use Throwable;
use App\Entity\Event;
use App\Entity\EventNotificationStatus;
use App\Entity\EventNotificationSubscription;
use App\Entity\UserMailConfig;
use App\Repository\EventNotificationSubscriptionRepository;
use App\Repository\EventRepository;
use App\Service\Mail\OrganizerMailerResolver;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class EventNotificationController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly EventNotificationSubscriptionRepository $subscriptions,
        private readonly OrganizerMailerResolver $mailerResolver,
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.visitor_email_signup')]
        private readonly RateLimiterFactoryInterface $signupLimiter,
        #[Autowire(service: 'limiter.confirm_email_resend')]
        private readonly RateLimiterFactoryInterface $confirmResendLimiter,
    ) {
    }

    #[Route(
        '/e/{slug}/notify',
        name: 'public_event_notify_subscribe',
        requirements: ['slug' => '[a-z0-9-]+'],
        methods: ['POST'],
    )]
    public function subscribe(string $slug, Request $request): Response
    {
        $event = $this->resolve($slug);

        // Honeypot: a filled hidden field means a bot — accept and drop silently.
        if ((string) $request->request->get('website', '') !== '') {
            return $this->checkInbox($event);
        }

        if (!$this->signupLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }

        // Feature must be enabled, owner mail active, and event not yet published.
        if (
            !$event->areNotificationsEnabled()
            || $event->isPublished()
            || !$this->mailerResolver->isCustomActive($event->getOwner())
        ) {
            return $this->checkInbox($event);
        }

        $email = strtolower(trim((string) $request->request->get('email', '')));
        if ($email === '' || count($this->validator->validate($email, [new Email()])) > 0) {
            return $this->checkInbox($event);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $existing = $this->subscriptions->findOneByEventAndEmail($event, $email);

        $shouldSend = false;
        if (!$existing instanceof EventNotificationSubscription) {
            $subscription = new EventNotificationSubscription($event, $email, $now);
            $this->em->persist($subscription);
            $shouldSend = true;
        } elseif ($existing->getStatus() === EventNotificationStatus::Confirmed) {
            // Already confirmed: no state change, no email (closes the mail-bomb vector).
            $subscription = $existing;
        } else {
            // pending (possibly expired) or unsubscribed: reset and re-send.
            $existing->restartPending($now);
            $subscription = $existing;
            $shouldSend = true;
        }

        $this->em->flush();

        if ($shouldSend && $this->confirmResendLimiter->create($email)->consume()->isAccepted()) {
            $this->sendConfirmation($event, $subscription);
        }

        return $this->checkInbox($event);
    }

    #[Route(
        '/e/{slug}/notify/confirm/{token}',
        name: 'public_event_notify_confirm',
        requirements: ['slug' => '[a-z0-9-]+', 'token' => '[A-Za-z0-9_-]+'],
        methods: ['GET'],
    )]
    public function confirm(string $slug, string $token): Response
    {
        $event = $this->resolve($slug);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $subscription = $this->subscriptions->findByConfirmationToken($token);

        if (!$subscription instanceof EventNotificationSubscription) {
            return $this->minimalPage('public/event_notification/invalid.html.twig');
        }

        // Idempotent: a repeat tap of an already-used (but valid) confirm link.
        if ($subscription->getStatus() === EventNotificationStatus::Confirmed) {
            return $this->minimalPage('public/event_notification/confirmed.html.twig', ['event' => $event]);
        }

        // Unsubscribed (or any other non-pending state) → generic invalid.
        if ($subscription->getStatus() !== EventNotificationStatus::Pending) {
            return $this->minimalPage('public/event_notification/invalid.html.twig');
        }

        // Pending but past the confirmation window → timed-out page.
        if ($subscription->isConfirmationExpired($now)) {
            return $this->minimalPage('public/event_notification/timed_out.html.twig', ['event' => $event]);
        }

        $subscription->confirm($now);
        $this->em->flush();

        return $this->minimalPage('public/event_notification/confirmed.html.twig', ['event' => $event]);
    }

    #[Route(
        '/e/{slug}/notify/unsubscribe/{token}',
        name: 'public_event_notify_unsubscribe',
        requirements: ['slug' => '[a-z0-9-]+', 'token' => '[A-Za-z0-9_-]+'],
        methods: ['GET'],
    )]
    public function unsubscribe(string $slug, string $token): Response
    {
        $this->resolve($slug);
        $subscription = $this->subscriptions->findByUnsubscribeToken($token);

        if (
            $subscription instanceof EventNotificationSubscription
            && $subscription->getStatus() !== EventNotificationStatus::Unsubscribed
        ) {
            $subscription->unsubscribe(new DateTimeImmutable('now', new DateTimeZone('UTC')));
            $this->em->flush();
        }

        return $this->minimalPage('public/event_notification/unsubscribed.html.twig');
    }

    private function sendConfirmation(Event $event, EventNotificationSubscription $subscription): void
    {
        $config = $event->getOwner()->getMailConfig();
        if (!$config instanceof UserMailConfig) {
            return;
        }

        try {
            $mailer = $this->mailerResolver->forEvent($event);
        } catch (Throwable $throwable) {
            $this->logger->error('Could not resolve organizer mailer for confirmation.', [
                'event_id' => $event->getId(),
                'exception' => $throwable->getMessage(),
            ]);

            return;
        }

        $confirmUrl = $this->generateUrl('public_event_notify_confirm', [
            'slug' => $event->getSlug(),
            'token' => $subscription->getConfirmationToken(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
        $unsubscribeUrl = $this->generateUrl('public_event_notify_unsubscribe', [
            'slug' => $event->getSlug(),
            'token' => $subscription->getUnsubscribeToken(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = new TemplatedEmail()
            ->from($config->getSenderAddress())
            ->to($subscription->getEmail())
            ->subject(sprintf('Confirm notifications for %s', $event->getName()))
            ->htmlTemplate('email/event-notification/confirm.html.twig')
            ->textTemplate('email/event-notification/confirm.txt.twig')
            ->context([
                'eventName' => $event->getName(),
                'confirmUrl' => $confirmUrl,
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);

        try {
            $mailer->send($email);
        } catch (Throwable $throwable) {
            $this->logger->error('Failed sending confirmation email.', [
                'event_id' => $event->getId(),
                'exception' => $throwable->getMessage(),
            ]);
        }
    }

    private function checkInbox(Event $event): Response
    {
        return $this->minimalPage('public/event_notification/check_inbox.html.twig', ['event' => $event]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function minimalPage(string $template, array $context = []): Response
    {
        $response = $this->render($template, $context);
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function resolve(string $slug): Event
    {
        $event = $this->events->findOneBySlug($slug);
        if (!$event instanceof Event) {
            throw new NotFoundHttpException(sprintf('No event for slug "%s".', $slug));
        }

        return $event;
    }
}
