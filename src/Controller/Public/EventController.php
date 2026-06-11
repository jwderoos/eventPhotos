<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\Event;
use App\Repository\EventRepository;
use App\Repository\PhotoRepository;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class EventController extends AbstractController
{
    private const int HARD_CAP = 200;

    private const string TIME_FORMAT = 'H:i';

    private const string TIME_PATTERN = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

    public function __construct(
        private readonly EventRepository $events,
        private readonly ClockInterface $clock,
        private readonly PhotoRepository $photos,
    ) {
    }

    #[Route('/e/{slug}', name: 'public_event_landing', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function landing(string $slug): Response
    {
        $event = $this->resolve($slug);
        $now   = $this->nowInEventTimezone($event);

        return $this->render('public/event/landing.html.twig', [
            'event'         => $event,
            'now'           => $now,
            'windowMinutes' => $event->resolveWindowMinutes(),
            'photosUrl'     => $this->buildPhotosUrl($event, $now),
        ]);
    }

    #[Route('/e/{slug}/photos', name: 'public_event_photos', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function photos(string $slug, Request $request): Response
    {
        $event = $this->resolve($slug);

        if ($request->query->has('w')) {
            throw new BadRequestHttpException('Window is no longer configurable per request.');
        }

        $timestamp = $this->resolveTimestamp($request->query->get('t'), $event);
        $window    = $event->resolveWindowMinutes();

        $start  = $timestamp->modify(sprintf('-%d minutes', $window));
        $end    = $timestamp->modify(sprintf('+%d minutes', $window));
        $photos = $this->photos->findReadyInWindow($event, $start, $end);

        return $this->render('public/event/photos.html.twig', [
            'event'     => $event,
            'timestamp' => $timestamp,
            'window'    => $window,
            'photos'    => $photos,
            'capHit'    => count($photos) === self::HARD_CAP,
        ]);
    }

    private function resolve(string $slug): Event
    {
        $event = $this->events->findOneBySlug($slug);

        if (!$event instanceof Event) {
            throw new NotFoundHttpException(sprintf('No event for slug "%s".', $slug));
        }

        return $event;
    }

    private function buildPhotosUrl(Event $event, DateTimeImmutable $when): string
    {
        return $this->generateUrl('public_event_photos', [
            'slug' => $event->getSlug(),
            't'    => $when->format(self::TIME_FORMAT),
        ]);
    }

    private function nowInEventTimezone(Event $event): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone($event->getTimezone()));
    }

    private function resolveTimestamp(mixed $raw, Event $event): DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return $this->nowInEventTimezone($event);
        }

        if (preg_match(self::TIME_PATTERN, $raw) !== 1) {
            throw new BadRequestHttpException('Invalid time. Expected HH:mm.');
        }

        $eventDate = $event->getDate()->format('Y-m-d');
        $resolved  = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i',
            sprintf('%s %s', $eventDate, $raw),
            new DateTimeZone($event->getTimezone()),
        );

        if (!$resolved instanceof DateTimeImmutable) {
            throw new BadRequestHttpException('Invalid time. Expected HH:mm.');
        }

        return $resolved;
    }
}
