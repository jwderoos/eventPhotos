<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\Event;
use App\Repository\EventRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class EventController extends AbstractController
{
    private const int MAX_WINDOW_MINUTES = 1440;

    public function __construct(
        private readonly EventRepository $events,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/e/{slug}', name: 'public_event_landing', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function landing(string $slug): Response
    {
        $event = $this->resolve($slug);
        $now   = $this->clock->now();

        return $this->render('public/event/landing.html.twig', [
            'event'         => $event,
            'now'           => $now,
            'windowMinutes' => $event->resolveWindowMinutes(),
            'photosUrl'     => $this->generateUrl('public_event_photos', [
                'slug' => $event->getSlug(),
                't'    => $now->format(DateTimeInterface::ATOM),
                'w'    => $event->resolveWindowMinutes(),
            ]),
        ]);
    }

    #[Route('/e/{slug}/photos', name: 'public_event_photos', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function photos(string $slug, Request $request): Response
    {
        $event = $this->resolve($slug);

        $timestamp = $this->parseTimestamp($request->query->get('t'));
        $window    = $this->parseWindow($request->query->get('w'), $event);

        return $this->render('public/event/photos.html.twig', [
            'event'     => $event,
            'timestamp' => $timestamp,
            'window'    => $window,
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

    private function parseTimestamp(mixed $raw): DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return $this->clock->now();
        }

        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $raw);

        if (!$parsed instanceof DateTimeImmutable) {
            throw new BadRequestHttpException('Invalid timestamp.');
        }

        return $parsed;
    }

    private function parseWindow(mixed $raw, Event $event): int
    {
        if ($raw === null || $raw === '') {
            return $event->resolveWindowMinutes();
        }

        if (!is_numeric($raw)) {
            throw new BadRequestHttpException('Invalid window.');
        }

        $window = (int) $raw;

        if ($window < 1 || $window > self::MAX_WINDOW_MINUTES) {
            throw new BadRequestHttpException('Invalid window.');
        }

        return $window;
    }
}
