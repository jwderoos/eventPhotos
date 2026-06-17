<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\Event;
use App\Entity\EventDisplayState;
use App\Entity\Photo;
use App\Entity\PhotoStatus;
use App\Repository\EventRepository;
use App\Repository\PhotoRepository;
use App\Service\Event\PhotosUrlBuilder;
use App\Service\QrCodeRenderer;
use DateTimeImmutable;
use DateTimeZone;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class EventController extends AbstractController
{
    private const int HARD_CAP = 200;

    private const int DISPLAY_QR_SIZE = 720;

    private const string TIME_PATTERN = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

    public function __construct(
        private readonly EventRepository $events,
        private readonly ClockInterface $clock,
        private readonly PhotoRepository $photos,
        private readonly PhotosUrlBuilder $photosUrl,
        private readonly QrCodeRenderer $qr,
        #[Autowire(service: 'event_logos_storage')]
        private readonly FilesystemOperator $eventLogosStorage,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/e/{slug}', name: 'public_event_landing', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function landing(string $slug): Response
    {
        $event = $this->resolve($slug);
        $now   = $this->nowInEventTimezone($event);
        $when  = $event->computeDisplayState($now) === EventDisplayState::Live
            ? $now
            : $this->startCursorInEventTimezone($event);

        return $this->render('public/event/landing.html.twig', [
            'event'             => $event,
            'now'               => $now,
            'photosUrl'         => $this->photosUrl->build($event, $when),
            'photosUrlAbsolute' => $this->photosUrl->build($event, $when, absolute: true),
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

        if (!$timestamp instanceof DateTimeImmutable) {
            return $this->redirectToRoute('public_event_photos', [
                'slug' => $event->getSlug(),
                't'    => $this->startCursorInEventTimezone($event)->format('H:i'),
            ]);
        }

        // HTTP cache validation (§3.3). Two cheap aggregate queries short-circuit
        // 6+ expensive queries below when the browser already has an up-to-date copy.
        // The cache key combines max(updatedAt) AND count(Ready): updatedAt catches
        // metadata mutations, count catches new/deleted/status-changed photos that
        // could land within the same second-precision timestamp tick (Postgres stores
        // `datetime_immutable` as timestamp(0) → seconds; a same-second batch ingest
        // wouldn't bump max alone).
        $lastUpdatedAt = $this->photos->lastReadyUpdatedAtForEvent($event);
        $readyCount    = $this->photos->countReady($event);
        $etag          = sha1(sprintf(
            '%d|%s|%d|%d|%s|%d',
            (int) $event->getId(),
            $timestamp->format('U'),
            Event::WINDOW_BEFORE_MINUTES,
            Event::WINDOW_AFTER_MINUTES,
            $lastUpdatedAt instanceof DateTimeImmutable ? $lastUpdatedAt->format('U.u') : '-',
            $readyCount,
        ));

        $response = new Response();
        $response->setEtag($etag);
        $response->setPublic();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('must-revalidate');

        if ($response->isNotModified($request)) {
            return $response;
        }

        $start  = $timestamp->modify(sprintf('-%d minutes', Event::WINDOW_BEFORE_MINUTES));
        $end    = $timestamp->modify(sprintf('+%d minutes', Event::WINDOW_AFTER_MINUTES));
        $photos = $this->photos->findReadyInWindow($event, $start, $end);

        // Cross-window navigation cursors (issues #62, #67). Anchored to the
        // window *edges*, not the cursor: prev/next/first/last must jump to
        // photos that are NOT already visible on the current page. If they
        // anchored on $timestamp, the closest neighbor often falls inside the
        // visible window and clicking the button slides the window by seconds
        // — perceived as a no-op (#67).
        // Caveat: `?t=` is `HH:mm` only, so for multi-day events the firstAt/lastAt
        // links may collapse back to the start day via `resolveTimestamp`. Acceptable
        // for now (per grooming note in #62); follow up only if real events trip on it.
        $earliestReady = $this->photos->findFirstReadyTakenAt($event);
        $latestReady   = $this->photos->findLastReadyTakenAt($event);
        $firstAt       = ($earliestReady instanceof DateTimeImmutable && $earliestReady < $start)
            ? $earliestReady
            : null;
        $lastAt        = ($latestReady instanceof DateTimeImmutable && $latestReady > $end)
            ? $latestReady
            : null;
        $prevAt        = $this->photos->findPreviousReadyTakenAt($event, $start);
        $nextAt        = $this->photos->findNextReadyTakenAt($event, $end);

        $totalReady = $readyCount;
        // Visible photos are a contiguous slice of the (takenAt, id) timeline,
        // so we only need the rank of the first one — the rest follow by index.
        $firstRank = $photos === [] ? null : $this->photos->countReadyBefore($photos[0]) + 1;

        return $this->render('public/event/photos.html.twig', [
            'event'        => $event,
            'timestamp'    => $timestamp,
            'windowBefore' => Event::WINDOW_BEFORE_MINUTES,
            'windowAfter'  => Event::WINDOW_AFTER_MINUTES,
            'photos'       => $photos,
            'capHit'       => count($photos) === self::HARD_CAP,
            'firstAt'      => $firstAt,
            'lastAt'       => $lastAt,
            'prevAt'       => $prevAt,
            'nextAt'       => $nextAt,
            'totalReady'   => $totalReady,
            'firstRank'    => $firstRank,
        ], $response);
    }

    #[Route(
        '/e/{slug}/photos/{id}/neighbor',
        name: 'public_event_photos_neighbor',
        requirements: ['slug' => '[a-z0-9-]+', 'id' => '\d+'],
        methods: ['GET'],
    )]
    public function photoNeighbor(string $slug, int $id, Request $request): Response
    {
        $event     = $this->resolve($slug);
        $direction = $request->query->get('direction');
        if ($direction !== 'next' && $direction !== 'prev') {
            throw new BadRequestHttpException('direction must be "next" or "prev".');
        }

        $photo = $this->photos->find($id);
        if (
            !$photo instanceof Photo
            || $photo->getEvent()->getId() !== $event->getId()
            || $photo->getStatus() !== PhotoStatus::Ready
        ) {
            throw new NotFoundHttpException();
        }

        $neighbor = $this->photos->findReadyNeighbor($photo, $direction);
        if (!$neighbor instanceof Photo) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return new JsonResponse([
            'id'         => $neighbor->getId(),
            'previewUrl' => $this->generateUrl('photo_serve_preview', ['slug' => $slug, 'id' => $neighbor->getId()]),
            'thumbUrl'   => $this->generateUrl('photo_serve_thumb', ['slug' => $slug, 'id' => $neighbor->getId()]),
        ]);
    }

    #[Route(
        '/e/{slug}/display',
        name: 'public_event_display',
        requirements: ['slug' => '[a-z0-9-]+'],
        methods: ['GET'],
    )]
    public function display(string $slug): Response
    {
        $event         = $this->resolve($slug);
        [$now, $state] = $this->resolveNowAndState($event);
        $photosUrl     = $this->buildPhotosUrlForState($event, $now, $state);
        $qrSvg         = $photosUrl === null
            ? null
            : $this->qr->svg(
                $photosUrl,
                $this->readLogoBytes($event),
                size: self::DISPLAY_QR_SIZE,
            );

        return $this->render('public/event/display.html.twig', [
            'event'     => $event,
            'now'       => $now,
            'state'     => $state,
            'photosUrl' => $photosUrl,
            'qrSvg'     => $qrSvg,
        ]);
    }

    #[Route(
        '/e/{slug}/display/qr.svg',
        name: 'public_event_display_qr',
        requirements: ['slug' => '[a-z0-9-]+'],
        methods: ['GET'],
    )]
    public function displayQr(string $slug): Response
    {
        $event         = $this->resolve($slug);
        [$now, $state] = $this->resolveNowAndState($event);

        if ($state === EventDisplayState::Post) {
            $response = new Response('', Response::HTTP_NO_CONTENT);
            $response->headers->set('X-Display-State', $state->value);

            return $response;
        }

        $photosUrl = $this->buildPhotosUrlForState($event, $now, $state);
        assert($photosUrl !== null);

        $svg = $this->qr->svg(
            $photosUrl,
            $this->readLogoBytes($event),
            size: self::DISPLAY_QR_SIZE,
        );

        $response = new Response($svg);
        $response->headers->set('Content-Type', 'image/svg+xml');
        // Note: Symfony's ResponseHeaderBag auto-appends `private` when no public/s-maxage
        // directive is set, so the wire value will be `no-store, private`. That's correct
        // (and harmless) for this anonymous public route.
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Display-State', $state->value);
        $response->headers->set('X-Photos-Url', $photosUrl);

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

    private function nowInEventTimezone(Event $event): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone($event->getTimezone()));
    }

    /**
     * Returns the in-window instant for $raw, or null when $raw is well-formed
     * but composes to an instant outside [startsAt, endsAt] on either event day.
     * Throws on missing/malformed input — only the outside-window case is a
     * fallback signal; bad HH:mm is still a 400.
     */
    private function resolveTimestamp(mixed $raw, Event $event): ?DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return $this->nowInEventTimezone($event);
        }

        if (preg_match(self::TIME_PATTERN, $raw) !== 1) {
            throw new BadRequestHttpException('Invalid time. Expected HH:mm.');
        }

        $tz       = new DateTimeZone($event->getTimezone());
        $startsAt = $event->getStartsAt();
        $endsAt   = $event->getEndsAt();
        $startDay = $startsAt->setTimezone($tz)->format('Y-m-d');
        $endDay   = $endsAt->setTimezone($tz)->format('Y-m-d');

        $candidate = $this->composeOnDay($startDay, $raw, $tz);
        if ($candidate >= $startsAt && $candidate <= $endsAt) {
            return $candidate;
        }

        if ($endDay !== $startDay) {
            $candidate = $this->composeOnDay($endDay, $raw, $tz);
            if ($candidate >= $startsAt && $candidate <= $endsAt) {
                return $candidate;
            }
        }

        return null;
    }

    private function startInEventTimezone(Event $event): DateTimeImmutable
    {
        return $event->getStartsAt()->setTimezone(new DateTimeZone($event->getTimezone()));
    }

    /**
     * Cursor used whenever we want to point a freshly-arriving viewer at "the
     * beginning" of the event: shifted forward by WINDOW_BEFORE_MINUTES so the
     * rendered window's leading edge sits on startsAt instead of before it.
     */
    private function startCursorInEventTimezone(Event $event): DateTimeImmutable
    {
        return $this->startInEventTimezone($event)
            ->modify(sprintf('+%d minutes', Event::WINDOW_BEFORE_MINUTES));
    }

    private function composeOnDay(string $day, string $time, DateTimeZone $tz): DateTimeImmutable
    {
        $resolved = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i',
            sprintf('%s %s', $day, $time),
            $tz,
        );

        if (!$resolved instanceof DateTimeImmutable) {
            throw new BadRequestHttpException('Invalid time. Expected HH:mm.');
        }

        return $resolved;
    }

    /**
     * @return array{0: DateTimeImmutable, 1: EventDisplayState}
     */
    private function resolveNowAndState(Event $event): array
    {
        $now = $this->nowInEventTimezone($event);

        return [$now, $event->computeDisplayState($now)];
    }

    private function buildPhotosUrlForState(
        Event $event,
        DateTimeImmutable $now,
        EventDisplayState $state,
    ): ?string {
        return match ($state) {
            EventDisplayState::Pre  => $this->photosUrl->build(
                $event,
                $this->startCursorInEventTimezone($event),
                absolute: true,
            ),
            EventDisplayState::Live => $this->photosUrl->build($event, $now, absolute: true),
            EventDisplayState::Post => null,
        };
    }

    private function readLogoBytes(Event $event): ?string
    {
        $filename = $event->getLogoFilename();
        if ($filename === null) {
            return null;
        }

        try {
            return $this->eventLogosStorage->read($filename);
        } catch (FilesystemException $filesystemException) {
            $this->logger->warning('Failed to read event logo; rendering QR without it', [
                'event_id'  => $event->getId(),
                'filename'  => $filename,
                'exception' => $filesystemException,
            ]);
            return null;
        }
    }
}
