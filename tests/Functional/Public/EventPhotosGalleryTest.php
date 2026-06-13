<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use Symfony\Component\HttpFoundation\Response;
use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EventPhotosGalleryTest extends WebTestCase
{
    public function testShowsReadyPhotosInsideWindow(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('g@example.test', 'G');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'gallery',
            'Gallery',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $event->setTimezone('UTC');

        $em->persist($event);

        $inside = new Photo($event, str_repeat('a', 64), 'a.jpg', 100);
        $inside->markReady(new DateTimeImmutable('2026-06-10 12:00:00', new DateTimeZone('UTC')), 100, 100, 1024);

        $em->persist($inside);

        $outside = new Photo($event, str_repeat('b', 64), 'b.jpg', 100);
        $outside->markReady(new DateTimeImmutable('2026-06-10 18:00:00', new DateTimeZone('UTC')), 100, 100, 1024);

        $em->persist($outside);

        $em->flush();

        $client->request(Request::METHOD_GET, '/e/gallery/photos?t=12:00');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString(sprintf('/p/%d/thumb.jpg', $inside->getId()), $content);
        $this->assertStringNotContainsString(sprintf('/p/%d/thumb.jpg', $outside->getId()), $content);
    }

    public function testAsymmetricWindowIncludesTenMinutesBeforeAndFiveAfter(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('asym@example.test', 'G');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'asym',
            'Asym',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $event->setTimezone('UTC');

        $em->persist($event);

        $tz = new DateTimeZone('UTC');

        $makePhoto = static function (string $hashChar, string $takenAt) use ($event, $em, $tz): Photo {
            $photo = new Photo($event, str_repeat($hashChar, 64), $hashChar . '.jpg', 100);
            $photo->markReady(new DateTimeImmutable($takenAt, $tz), 100, 100, 1024);

            $em->persist($photo);

            return $photo;
        };

        $tooEarly  = $makePhoto('a', '2026-06-10 11:49:59'); // 10:01 before 12:00 → excluded
        $lowerEdge = $makePhoto('b', '2026-06-10 11:50:00'); // exactly t - 10  → included
        $inside    = $makePhoto('c', '2026-06-10 12:00:00');
        $upperEdge = $makePhoto('d', '2026-06-10 12:05:00'); // exactly t + 5   → included
        $tooLate   = $makePhoto('e', '2026-06-10 12:05:01'); // 5:01 after      → excluded
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/asym/photos?t=12:00');
        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        foreach ([$lowerEdge, $inside, $upperEdge] as $included) {
            $this->assertStringContainsString(
                sprintf('/p/%d/thumb.jpg', $included->getId()),
                $content,
                'Photo at boundary or inside [t-10, t+5] must be shown',
            );
        }

        foreach ([$tooEarly, $tooLate] as $excluded) {
            $this->assertStringNotContainsString(
                sprintf('/p/%d/thumb.jpg', $excluded->getId()),
                $content,
                'Photo outside [t-10, t+5] must be hidden',
            );
        }
    }

    public function testRendersLightboxMarkup(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('lb@example.test', 'L');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'lb',
            'Lightbox',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $event->setTimezone('UTC');

        $em->persist($event);

        $photo = new Photo($event, str_repeat('d', 64), 'd.jpg', 100);
        $photo->markReady(new DateTimeImmutable('2026-06-10 12:00:00', new DateTimeZone('UTC')), 100, 100, 1024);

        $em->persist($photo);
        $em->flush();

        $crawler = $client->request(Request::METHOD_GET, '/e/lb/photos?t=12:00');

        $this->assertResponseIsSuccessful();

        $controllerNode = $crawler->filter('[data-controller~="lightbox"]');
        $this->assertGreaterThan(0, $controllerNode->count(), 'Grid must be mounted as a lightbox controller');

        $trigger = $crawler->filter('li[data-lightbox-target="trigger"]');
        $this->assertCount(1, $trigger, 'Each photo tile must declare itself as a lightbox trigger');
        $this->assertSame((string) $photo->getId(), $trigger->attr('data-photo-id'));
        $this->assertSame(sprintf('/e/lb/p/%d/preview.jpg', $photo->getId()), $trigger->attr('data-preview-url'));

        $anchor = $trigger->filter('a');
        $this->assertSame(
            sprintf('/e/lb/p/%d/preview.jpg', $photo->getId()),
            $anchor->attr('href'),
            'Anchor must keep its href for graceful degradation',
        );
        $this->assertNull($anchor->attr('target'), 'Lightbox replaces new-tab open; target attribute must be dropped');

        $dialog = $crawler->filter('dialog[data-lightbox-target="dialog"]');
        $this->assertCount(1, $dialog, 'Lightbox dialog element must be rendered');
    }

    public function testHidesPendingPhotos(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('g2@example.test', 'G');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'g2',
            'G2',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $event->setTimezone('UTC');

        $em->persist($event);

        $pending = new Photo($event, str_repeat('c', 64), 'c.jpg', 100);
        $em->persist($pending);
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/g2/photos?t=12:00');

        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString(
            sprintf('/p/%d/thumb.jpg', $pending->getId()),
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testNavAllDisabledWhenNoReadyPhotos(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('nav-empty@example.test', 'N');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'nav-empty',
            'NavEmpty',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $event->setTimezone('UTC');

        $em->persist($event);
        $em->flush();

        $crawler = $client->request(Request::METHOD_GET, '/e/nav-empty/photos?t=12:00');
        $this->assertResponseIsSuccessful();

        foreach (['nav-first', 'nav-prev', 'nav-next', 'nav-last'] as $testId) {
            $node = $crawler->filter(sprintf('[data-testid="%s"]', $testId));
            $this->assertCount(1, $node, sprintf('Expected %s element to render', $testId));
            $this->assertSame('true', $node->attr('aria-disabled'), sprintf('%s should be disabled', $testId));
            $this->assertCount(0, $node->filter('a'), sprintf('%s must not be a clickable <a>', $testId));
        }
    }

    public function testNavSinglePhotoInsideWindowAllDisabled(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('nav-single@example.test', 'N');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'nav-single',
            'NavSingle',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $event->setTimezone('UTC');

        $em->persist($event);

        $only = new Photo($event, str_repeat('a', 64), 'a.jpg', 100);
        $only->markReady(new DateTimeImmutable('2026-06-10 12:15:00', new DateTimeZone('UTC')), 100, 100, 1024);

        $em->persist($only);
        $em->flush();

        // Visit with ?t=12:15 — the only photo is inside the current window
        // [12:05, 12:20], so every nav direction would land on a page that
        // already shows it. All four cursors must be disabled (#67).
        $crawler = $client->request(Request::METHOD_GET, '/e/nav-single/photos?t=12:15');
        $this->assertResponseIsSuccessful();

        foreach (['nav-first', 'nav-prev', 'nav-next', 'nav-last'] as $testId) {
            $node = $crawler->filter(sprintf('[data-testid="%s"]', $testId));
            $this->assertCount(1, $node);
            $this->assertSame('true', $node->attr('aria-disabled'), sprintf('%s should be disabled', $testId));
            $this->assertCount(0, $node->filter('a'), sprintf('%s must not be a clickable <a>', $testId));
        }
    }

    /**
     * Regression for #67. Prev/Next must jump to photos *outside* the current
     * visible window, otherwise dense areas of the timeline produce buttons
     * that visibly do nothing.
     */
    public function testNavSkipsPhotosAlreadyInsideTheVisibleWindow(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('nav-skip@example.test', 'N');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'nav-skip',
            'NavSkip',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $event->setTimezone('UTC');

        $em->persist($event);

        $tz = new DateTimeZone('UTC');
        $makePhoto = static function (string $hashChar, string $takenAt) use ($event, $em, $tz): void {
            $photo = new Photo($event, str_repeat($hashChar, 64), $hashChar . '.jpg', 100);
            $photo->markReady(new DateTimeImmutable($takenAt, $tz), 100, 100, 1024);

            $em->persist($photo);
        };

        // Window for ?t=12:10 is [12:00, 12:15]. 12:00 and 12:12 are inside;
        // 11:00 and 12:20 are outside — those are the valid prev/next targets.
        $makePhoto('a', '2026-06-10 11:00:00'); // outside (before)
        $makePhoto('b', '2026-06-10 12:00:00'); // inside (start boundary)
        $makePhoto('c', '2026-06-10 12:12:00'); // inside
        $makePhoto('d', '2026-06-10 12:20:00'); // outside (after)
        $em->flush();

        $crawler = $client->request(Request::METHOD_GET, '/e/nav-skip/photos?t=12:10');
        $this->assertResponseIsSuccessful();

        $prevHref = (string) $crawler->filter('[data-testid="nav-prev"] a')->attr('href');
        $nextHref = (string) $crawler->filter('[data-testid="nav-next"] a')->attr('href');

        $this->assertStringContainsString(
            't=11:00',
            $prevHref,
            'Previous must skip over photos inside the current window',
        );
        $this->assertStringContainsString(
            't=12:20',
            $nextHref,
            'Next must skip over photos inside the current window',
        );
    }

    public function testNavCursorBeforeFirstPhoto(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('nav-before@example.test', 'N');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'nav-before',
            'NavBefore',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $event->setTimezone('UTC');

        $em->persist($event);

        $tz = new DateTimeZone('UTC');
        $earliest = new Photo($event, str_repeat('a', 64), 'a.jpg', 100);
        $earliest->markReady(new DateTimeImmutable('2026-06-10 12:00:00', $tz), 100, 100, 1024);

        $latest = new Photo($event, str_repeat('b', 64), 'b.jpg', 100);
        $latest->markReady(new DateTimeImmutable('2026-06-10 13:30:00', $tz), 100, 100, 1024);

        $em->persist($earliest);
        $em->persist($latest);
        $em->flush();

        // ?t=11:30 — strictly before the earliest Ready photo (12:00)
        $crawler = $client->request(Request::METHOD_GET, '/e/nav-before/photos?t=11:30');
        $this->assertResponseIsSuccessful();

        $this->assertSame('true', $crawler->filter('[data-testid="nav-first"]')->attr('aria-disabled'));
        $this->assertSame('true', $crawler->filter('[data-testid="nav-prev"]')->attr('aria-disabled'));

        $nextHref = $crawler->filter('[data-testid="nav-next"] a')->attr('href');
        $lastHref = $crawler->filter('[data-testid="nav-last"] a')->attr('href');
        $this->assertNotNull($nextHref);
        $this->assertNotNull($lastHref);
        $this->assertStringContainsString('t=12:00', $nextHref);
        $this->assertStringContainsString('t=13:30', $lastHref);
    }

    public function testNavCursorAfterLastPhoto(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('nav-after@example.test', 'N');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'nav-after',
            'NavAfter',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $event->setTimezone('UTC');

        $em->persist($event);

        $tz = new DateTimeZone('UTC');
        $earliest = new Photo($event, str_repeat('a', 64), 'a.jpg', 100);
        $earliest->markReady(new DateTimeImmutable('2026-06-10 12:00:00', $tz), 100, 100, 1024);

        $latest = new Photo($event, str_repeat('b', 64), 'b.jpg', 100);
        $latest->markReady(new DateTimeImmutable('2026-06-10 13:30:00', $tz), 100, 100, 1024);

        $em->persist($earliest);
        $em->persist($latest);
        $em->flush();

        // ?t=13:45 — strictly after the latest Ready photo (13:30)
        $crawler = $client->request(Request::METHOD_GET, '/e/nav-after/photos?t=13:45');
        $this->assertResponseIsSuccessful();

        $this->assertSame('true', $crawler->filter('[data-testid="nav-next"]')->attr('aria-disabled'));
        $this->assertSame('true', $crawler->filter('[data-testid="nav-last"]')->attr('aria-disabled'));

        $firstHref = $crawler->filter('[data-testid="nav-first"] a')->attr('href');
        $prevHref  = $crawler->filter('[data-testid="nav-prev"] a')->attr('href');
        $this->assertNotNull($firstHref);
        $this->assertNotNull($prevHref);
        $this->assertStringContainsString('t=12:00', $firstHref);
        $this->assertStringContainsString('t=13:30', $prevHref);
    }

    public function testNavCursorBetweenPhotosAllEnabled(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('nav-mid@example.test', 'N');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'nav-mid',
            'NavMid',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $event->setTimezone('UTC');

        $em->persist($event);

        $tz = new DateTimeZone('UTC');
        $hashChars = ['a', 'b', 'c'];
        foreach (['11:00', '12:00', '13:00'] as $i => $hhmm) {
            $p = new Photo($event, str_repeat($hashChars[$i], 64), $hhmm . '.jpg', 100);
            $p->markReady(new DateTimeImmutable('2026-06-10 ' . $hhmm . ':00', $tz), 100, 100, 1024);
            $em->persist($p);
        }

        $em->flush();

        // ?t=12:30 — strictly between 12:00 and 13:00
        $crawler = $client->request(Request::METHOD_GET, '/e/nav-mid/photos?t=12:30');
        $this->assertResponseIsSuccessful();

        foreach (['nav-first', 'nav-prev', 'nav-next', 'nav-last'] as $testId) {
            $this->assertCount(
                1,
                $crawler->filter(sprintf('[data-testid="%s"] a', $testId)),
                sprintf('%s should be enabled (have an <a>) when cursor sits between photos', $testId),
            );
        }

        $firstHref = (string) $crawler->filter('[data-testid="nav-first"] a')->attr('href');
        $prevHref  = (string) $crawler->filter('[data-testid="nav-prev"] a')->attr('href');
        $nextHref  = (string) $crawler->filter('[data-testid="nav-next"] a')->attr('href');
        $lastHref  = (string) $crawler->filter('[data-testid="nav-last"] a')->attr('href');
        $this->assertStringContainsString('t=11:00', $firstHref);
        $this->assertStringContainsString('t=12:00', $prevHref);
        $this->assertStringContainsString('t=13:00', $nextHref);
        $this->assertStringContainsString('t=13:00', $lastHref);
    }

    public function testPhotoNeighborEndpointReturnsNextAndPrev(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('neighbor@example.test', 'N');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'neighbor',
            'Neighbor',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $event->setTimezone('UTC');

        $em->persist($event);

        $tz = new DateTimeZone('UTC');
        $earlier = new Photo($event, str_repeat('a', 64), 'a.jpg', 100);
        $earlier->markReady(new DateTimeImmutable('2026-06-10 11:00:00', $tz), 100, 100, 1024);

        $middle = new Photo($event, str_repeat('b', 64), 'b.jpg', 100);
        $middle->markReady(new DateTimeImmutable('2026-06-10 12:00:00', $tz), 100, 100, 1024);

        $later = new Photo($event, str_repeat('c', 64), 'c.jpg', 100);
        $later->markReady(new DateTimeImmutable('2026-06-10 13:00:00', $tz), 100, 100, 1024);

        $em->persist($earlier);
        $em->persist($middle);
        $em->persist($later);
        $em->flush();

        $client->request(
            Request::METHOD_GET,
            sprintf('/e/neighbor/photos/%d/neighbor?direction=next', $middle->getId()),
        );
        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($payload);
        $this->assertSame($later->getId(), $payload['id']);
        $this->assertSame(
            sprintf('/e/neighbor/p/%d/preview.jpg', $later->getId()),
            $payload['previewUrl'],
        );
        $this->assertSame(
            sprintf('/e/neighbor/p/%d/thumb.jpg', $later->getId()),
            $payload['thumbUrl'],
        );

        $client->request(
            Request::METHOD_GET,
            sprintf('/e/neighbor/photos/%d/neighbor?direction=prev', $middle->getId()),
        );
        $this->assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($payload);
        $this->assertSame($earlier->getId(), $payload['id']);
    }

    public function testPhotoNeighborEndpointReturns204AtEndOfTimeline(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('nb-end@example.test', 'N');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'nb-end',
            'NbEnd',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $event->setTimezone('UTC');

        $em->persist($event);

        $only = new Photo($event, str_repeat('a', 64), 'a.jpg', 100);
        $only->markReady(new DateTimeImmutable('2026-06-10 12:00:00', new DateTimeZone('UTC')), 100, 100, 1024);

        $em->persist($only);
        $em->flush();

        foreach (['next', 'prev'] as $direction) {
            $client->request(
                Request::METHOD_GET,
                sprintf('/e/nb-end/photos/%d/neighbor?direction=%s', $only->getId(), $direction),
            );
            $this->assertSame(
                Response::HTTP_NO_CONTENT,
                $client->getResponse()->getStatusCode(),
                sprintf('Direction %s', $direction),
            );
            $this->assertSame('', (string) $client->getResponse()->getContent());
        }
    }

    public function testPhotoNeighborEndpointRejectsCrossEventLookup(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('nb-cross@example.test', 'N');
        $owner->setPassword('x');

        $em->persist($owner);

        $eventA = new Event(
            'nb-cross-a',
            'NbCrossA',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $eventA->setTimezone('UTC');

        $eventB = new Event(
            'nb-cross-b',
            'NbCrossB',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $eventB->setTimezone('UTC');

        $em->persist($eventA);
        $em->persist($eventB);

        $photoB = new Photo($eventB, str_repeat('a', 64), 'a.jpg', 100);
        $photoB->markReady(new DateTimeImmutable('2026-06-10 12:00:00', new DateTimeZone('UTC')), 100, 100, 1024);

        $em->persist($photoB);
        $em->flush();

        $client->request(
            Request::METHOD_GET,
            sprintf('/e/nb-cross-a/photos/%d/neighbor?direction=next', $photoB->getId()),
        );
        $this->assertSame(
            Response::HTTP_NOT_FOUND,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testPhotoNeighborEndpointRejectsInvalidDirection(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('nb-bad@example.test', 'N');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'nb-bad',
            'NbBad',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $event->setTimezone('UTC');

        $em->persist($event);

        $photo = new Photo($event, str_repeat('a', 64), 'a.jpg', 100);
        $photo->markReady(new DateTimeImmutable('2026-06-10 12:00:00', new DateTimeZone('UTC')), 100, 100, 1024);

        $em->persist($photo);
        $em->flush();

        $client->request(
            Request::METHOD_GET,
            sprintf('/e/nb-bad/photos/%d/neighbor?direction=sideways', $photo->getId()),
        );
        $this->assertSame(
            Response::HTTP_BAD_REQUEST,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
    }

    /**
     * Regression — Photo::$takenAt is stored as tz-less wall-clock UTC. When the
     * event tz has a non-zero offset, clicking nav-first or typing ?t= in event
     * tz used to bind the wrong wall-clock to the BETWEEN query and the photo
     * would not appear. PhotoRepository now re-anchors all DateTimeImmutable
     * params to UTC at the boundary.
     */
    public function testNavRoundTripFindsPhotoInNonUtcEventTimezone(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('nav-tz@example.test', 'N');
        $owner->setPassword('x');

        $em->persist($owner);

        // Event in Europe/Amsterdam (CEST = +02:00 on 2026-06-10).
        $event = new Event(
            'nav-tz',
            'NavTz',
            new DateTimeImmutable('2026-06-10 10:00:00', new DateTimeZone('Europe/Amsterdam')),
            new DateTimeImmutable('2026-06-10 18:00:00', new DateTimeZone('Europe/Amsterdam')),
            $owner,
        );
        $event->setTimezone('Europe/Amsterdam');

        $em->persist($event);

        // Photo's `takenAt` matches what ExifReader produces: a UTC-tagged
        // instant whose Amsterdam wall-clock is 14:00 (so UTC wall-clock 12:00).
        $photo = new Photo($event, str_repeat('a', 64), 'a.jpg', 100);
        $photo->markReady(
            new DateTimeImmutable('2026-06-10 14:00:00', new DateTimeZone('Europe/Amsterdam'))
                ->setTimezone(new DateTimeZone('UTC')),
            100,
            100,
            1024,
        );
        $em->persist($photo);
        $em->flush();

        // Land at 15:00 (one hour after the photo) — cursor is after the
        // photo, so nav-first should be enabled and link to 14:00.
        $crawler = $client->request(Request::METHOD_GET, '/e/nav-tz/photos?t=15:00');
        $this->assertResponseIsSuccessful();

        $firstHref = (string) $crawler->filter('[data-testid="nav-first"] a')->attr('href');
        $this->assertStringContainsString('t=14:00', $firstHref);

        // Following the link must show the photo (this was broken before the
        // UTC normalisation fix — the BETWEEN window was bound in Amsterdam
        // wall-clock and missed the UTC wall-clock stored on the row).
        $client->request(Request::METHOD_GET, $firstHref);
        $this->assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString(
            sprintf('/p/%d/thumb.jpg', $photo->getId()),
            $body,
            'Photo should be visible after navigating via [« First] in a non-UTC event',
        );
    }
}
