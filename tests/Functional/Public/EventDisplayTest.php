<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;

final class EventDisplayTest extends WebTestCase
{
    public function testDisplayPageInLiveStateRendersTimestampedQr(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var MockClock $clock */
        $clock = $container->get(ClockInterface::class);

        $owner = new User('display-owner@example.test', 'O');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'big-night',
            'Big Night',
            new DateTimeImmutable('2026-06-12 10:00', new DateTimeZone('Europe/Amsterdam')),
            new DateTimeImmutable('2026-06-12 14:00', new DateTimeZone('Europe/Amsterdam')),
            $owner,
        );
        $event->setTimezone('Europe/Amsterdam');

        $em->persist($event);
        $em->flush();

        // 12:00 Europe/Amsterdam in June = 10:00 UTC.
        $clock->modify('2026-06-12 10:00:00');

        $client->request(Request::METHOD_GET, '/e/big-night/display');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');

        $html = (string) $client->getResponse()->getContent();

        $this->assertStringContainsString('Big Night', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('data-qr-refresh-state-value="live"', $html);
        $this->assertStringContainsString(
            'data-qr-refresh-endpoint-value="/e/big-night/display/qr.svg"',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '#href="https?://[^"]+/e/big-night/photos\?t=12:00"#',
            $html,
        );
    }

    public function testDisplayPage404sForUnknownSlug(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        // Seed a user so FirstRunBootstrapSubscriber doesn't redirect to /setup.
        $owner = new User('display-404@example.test', 'O');
        $owner->setPassword('x');

        $em->persist($owner);
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/does-not-exist/display');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testRefreshEndpointCarriesNoStoreCacheControl(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var MockClock $clock */
        $clock = $container->get(ClockInterface::class);

        $owner = new User('refresh-owner@example.test', 'O');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'refresh-night',
            'Refresh Night',
            new DateTimeImmutable('2026-06-12 10:00', new DateTimeZone('Europe/Amsterdam')),
            new DateTimeImmutable('2026-06-12 14:00', new DateTimeZone('Europe/Amsterdam')),
            $owner,
        );
        $event->setTimezone('Europe/Amsterdam');

        $em->persist($event);
        $em->flush();

        $clock->modify('2026-06-12 10:00:00');

        $client->request(Request::METHOD_GET, '/e/refresh-night/display/qr.svg');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'image/svg+xml');
        // Symfony auto-appends `private` to the Cache-Control header because no
        // public/s-maxage directive is set on the response.
        $this->assertResponseHeaderSame('Cache-Control', 'no-store, private');

        $svg = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('<svg', $svg);
    }

    public function testRefreshEndpoint404sForUnknownSlug(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        // Seed a user so FirstRunBootstrapSubscriber doesn't redirect to /setup.
        $owner = new User('refresh-404@example.test', 'O');
        $owner->setPassword('x');

        $em->persist($owner);
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/does-not-exist/display/qr.svg');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDisplayPageInPreEventStateRendersStaticQrAnchoredToStartsAt(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var MockClock $clock */
        $clock = $container->get(ClockInterface::class);

        $owner = new User('pre-owner@example.test', 'O');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'pre-night',
            'Pre Night',
            new DateTimeImmutable('2026-07-15 19:00', new DateTimeZone('Europe/Amsterdam')),
            new DateTimeImmutable('2026-07-15 23:00', new DateTimeZone('Europe/Amsterdam')),
            $owner,
        );
        $event->setTimezone('Europe/Amsterdam');

        $em->persist($event);
        $em->flush();

        $clock->modify('2026-07-15 16:00:00');

        $client->request(Request::METHOD_GET, '/e/pre-night/display');

        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $this->assertStringContainsString('Pre Night', $html);
        $this->assertStringContainsString('data-qr-refresh-state-value="pre"', $html);
        $this->assertMatchesRegularExpression(
            '#href="https?://[^"]+/e/pre-night/photos\?t=19:10"#',
            $html,
        );
        $this->assertStringContainsString('<svg', $html);
        $this->assertMatchesRegularExpression('#Starts\s*<time[^>]*>19:00</time>#', $html);
    }

    public function testDisplayPageAtStartsAtBoundaryIsLive(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var MockClock $clock */
        $clock = $container->get(ClockInterface::class);

        $owner = new User('boundary-start@example.test', 'O');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'start-edge',
            'Start Edge',
            new DateTimeImmutable('2026-06-12 10:00', new DateTimeZone('Europe/Amsterdam')),
            new DateTimeImmutable('2026-06-12 14:00', new DateTimeZone('Europe/Amsterdam')),
            $owner,
        );
        $event->setTimezone('Europe/Amsterdam');

        $em->persist($event);
        $em->flush();

        // 10:00 Europe/Amsterdam (startsAt) in June = 08:00 UTC.
        $clock->modify('2026-06-12 08:00:00');

        $client->request(Request::METHOD_GET, '/e/start-edge/display');

        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('data-qr-refresh-state-value="live"', $html);
    }

    public function testDisplayPageAtEndsAtBoundaryIsLive(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var MockClock $clock */
        $clock = $container->get(ClockInterface::class);

        $owner = new User('boundary-end@example.test', 'O');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'end-edge',
            'End Edge',
            new DateTimeImmutable('2026-06-12 10:00', new DateTimeZone('Europe/Amsterdam')),
            new DateTimeImmutable('2026-06-12 14:00', new DateTimeZone('Europe/Amsterdam')),
            $owner,
        );
        $event->setTimezone('Europe/Amsterdam');

        $em->persist($event);
        $em->flush();

        // 14:00 Europe/Amsterdam (endsAt) in June = 12:00 UTC.
        $clock->modify('2026-06-12 12:00:00');

        $client->request(Request::METHOD_GET, '/e/end-edge/display');

        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('data-qr-refresh-state-value="live"', $html);
    }

    public function testDisplayPageInPostEventStateHasNoQrAndShowsEndedMessage(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var MockClock $clock */
        $clock = $container->get(ClockInterface::class);

        $owner = new User('post-owner@example.test', 'O');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'past-night',
            'Past Night',
            new DateTimeImmutable('2026-06-12 10:00', new DateTimeZone('Europe/Amsterdam')),
            new DateTimeImmutable('2026-06-12 14:00', new DateTimeZone('Europe/Amsterdam')),
            $owner,
        );
        $event->setTimezone('Europe/Amsterdam');

        $em->persist($event);
        $em->flush();

        // 15:00 Europe/Amsterdam (past endsAt) in June = 13:00 UTC.
        $clock->modify('2026-06-12 13:00:00');

        $client->request(Request::METHOD_GET, '/e/past-night/display');

        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $this->assertStringContainsString('Past Night', $html);
        $this->assertStringContainsString('This event has ended.', $html);
        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringNotContainsString('data-controller="qr-refresh"', $html);
        $this->assertStringNotContainsString('data-qr-refresh-state-value', $html);
    }

    public function testRefreshEndpointInPreStateReturnsSvgAndStateHeaders(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var MockClock $clock */
        $clock = $container->get(ClockInterface::class);

        $owner = new User('refresh-pre@example.test', 'O');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'refresh-pre',
            'Refresh Pre',
            new DateTimeImmutable('2026-07-15 19:00', new DateTimeZone('Europe/Amsterdam')),
            new DateTimeImmutable('2026-07-15 23:00', new DateTimeZone('Europe/Amsterdam')),
            $owner,
        );
        $event->setTimezone('Europe/Amsterdam');

        $em->persist($event);
        $em->flush();

        // 14:00 UTC = 16:00 Amsterdam, comfortably before the 19:00 start.
        $clock->modify('2026-07-15 14:00:00');

        $client->request(Request::METHOD_GET, '/e/refresh-pre/display/qr.svg');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'image/svg+xml');
        $this->assertResponseHeaderSame('X-Display-State', 'pre');
        $photosUrl = $client->getResponse()->headers->get('X-Photos-Url') ?? '';
        $this->assertMatchesRegularExpression(
            '#^https?://[^/]+/e/refresh-pre/photos\?t=19:10$#',
            $photosUrl,
        );
        $this->assertStringContainsString('<svg', (string) $client->getResponse()->getContent());
    }

    public function testRefreshEndpointInLiveStateReturnsSvgAndLiveHeader(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var MockClock $clock */
        $clock = $container->get(ClockInterface::class);

        $owner = new User('refresh-live@example.test', 'O');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'refresh-live',
            'Refresh Live',
            new DateTimeImmutable('2026-06-12 10:00', new DateTimeZone('Europe/Amsterdam')),
            new DateTimeImmutable('2026-06-12 14:00', new DateTimeZone('Europe/Amsterdam')),
            $owner,
        );
        $event->setTimezone('Europe/Amsterdam');

        $em->persist($event);
        $em->flush();

        // 10:00 UTC = 12:00 Amsterdam, inside the window.
        $clock->modify('2026-06-12 10:00:00');

        $client->request(Request::METHOD_GET, '/e/refresh-live/display/qr.svg');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'image/svg+xml');
        $this->assertResponseHeaderSame('X-Display-State', 'live');
        $photosUrl = $client->getResponse()->headers->get('X-Photos-Url') ?? '';
        $this->assertMatchesRegularExpression(
            '#^https?://[^/]+/e/refresh-live/photos\?t=12:00$#',
            $photosUrl,
        );
    }

    public function testRefreshEndpointInPostStateReturns204WithStateHeader(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var MockClock $clock */
        $clock = $container->get(ClockInterface::class);

        $owner = new User('refresh-post@example.test', 'O');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'refresh-post',
            'Refresh Post',
            new DateTimeImmutable('2026-06-12 10:00', new DateTimeZone('Europe/Amsterdam')),
            new DateTimeImmutable('2026-06-12 14:00', new DateTimeZone('Europe/Amsterdam')),
            $owner,
        );
        $event->setTimezone('Europe/Amsterdam');

        $em->persist($event);
        $em->flush();

        // 13:00 UTC = 15:00 Amsterdam, after the 14:00 end.
        $clock->modify('2026-06-12 13:00:00');

        $client->request(Request::METHOD_GET, '/e/refresh-post/display/qr.svg');

        $this->assertResponseStatusCodeSame(204);
        $this->assertResponseHeaderSame('X-Display-State', 'post');
        $this->assertEmpty((string) $client->getResponse()->getContent());
        $this->assertFalse($client->getResponse()->headers->has('X-Photos-Url'));
    }
}
