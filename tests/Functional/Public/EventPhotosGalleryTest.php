<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

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

    public function testNavSinglePhotoFirstAndLastShareHrefPrevNextDisabled(): void
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

        // Visit with ?t=12:15 — cursor sits exactly on the only photo.
        $crawler = $client->request(Request::METHOD_GET, '/e/nav-single/photos?t=12:15');
        $this->assertResponseIsSuccessful();

        $first = $crawler->filter('[data-testid="nav-first"]');
        $last  = $crawler->filter('[data-testid="nav-last"]');
        $prev  = $crawler->filter('[data-testid="nav-prev"]');
        $next  = $crawler->filter('[data-testid="nav-next"]');

        // First & Last should each render an <a> pointing at ?t=12:15
        $this->assertCount(1, $first->filter('a'));
        $this->assertCount(1, $last->filter('a'));
        $this->assertStringContainsString('t=12:15', $first->filter('a')->attr('href') ?? '');
        $this->assertStringContainsString('t=12:15', $last->filter('a')->attr('href') ?? '');

        // Previous & Next disabled (no photo strictly to either side)
        $this->assertSame('true', $prev->attr('aria-disabled'));
        $this->assertSame('true', $next->attr('aria-disabled'));
        $this->assertCount(0, $prev->filter('a'));
        $this->assertCount(0, $next->filter('a'));
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
}
