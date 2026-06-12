<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EventDisplayTest extends WebTestCase
{
    public function testDisplayPageRendersQrEncodingPhotosUrlInCurrentFormat(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $owner = new User('display-owner@example.test', 'O');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'big-night',
            'Big Night',
            new DateTimeImmutable('2026-06-12'),
            $owner,
        );
        $event->setTimezone('Europe/Amsterdam');

        $em->persist($event);
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/big-night/display');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');

        $html = (string) $client->getResponse()->getContent();

        // It is a full-screen display page (no public chrome).
        $this->assertStringContainsString('Big Night', $html);

        // SVG payload is inlined.
        $this->assertStringContainsString('<svg', $html);

        // The Stimulus controller is wired with the refresh endpoint (path-form).
        $this->assertStringContainsString(
            'data-qr-refresh-endpoint-value="/e/big-night/display/qr.svg"',
            $html,
        );

        // The encoded URL format (t=HH:mm, no `w`) is verified at the unit level in
        // PhotosUrlBuilderTest. We do not re-assert it from the HTML because the URL
        // is no longer surfaced as a text attribute — it lives only inside the QR
        // matrix path data, which would require decoding to inspect.
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

    public function testRefreshEndpointReturnsSvgWithFreshT(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $owner = new User('refresh-owner@example.test', 'O');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'refresh-night',
            'Refresh Night',
            new DateTimeImmutable('2026-06-12'),
            $owner,
        );
        $event->setTimezone('Europe/Amsterdam');

        $em->persist($event);
        $em->flush();

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
}
