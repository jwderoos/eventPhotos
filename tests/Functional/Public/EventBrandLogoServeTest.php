<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\OrganizerProfile;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EventBrandLogoServeTest extends WebTestCase
{
    public function testServesBrandLogoBytesForConfiguredBrand(): void
    {
        $client = self::createClient();
        $c = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var FilesystemOperator $storage */
        $storage = $c->get('brand_logos_storage');

        $owner = new User('brand-serve@example.com', 'Owner');
        $owner->setPassword('x');

        $event = new Event(
            'brand-serve-slug',
            'Brand Serve Event',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );

        $profile = new OrganizerProfile($owner);
        $profile->setBrandLogoFilename('brand-serve.png');
        // 1x1 transparent PNG
        $storage->write('brand-serve.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
        ));

        $em->persist($owner);
        $em->persist($event);
        $em->persist($profile);
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/brand-serve-slug/brand-logo.png');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
    }

    public function testReturns404WhenNoBrandLogo(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('brand-nologo@example.com', 'Owner');
        $owner->setPassword('x');

        $event = new Event(
            'brand-nologo-slug',
            'No Logo Event',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );
        $em->persist($owner);
        $em->persist($event);
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/brand-nologo-slug/brand-logo.png');

        self::assertResponseStatusCodeSame(404);
    }

    public function testReturns304OnMatchingIfNoneMatch(): void
    {
        $client = self::createClient();
        $c = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var FilesystemOperator $storage */
        $storage = $c->get('brand_logos_storage');

        $owner = new User('brand-etag@example.com', 'Owner');
        $owner->setPassword('x');

        $event = new Event(
            'brand-etag-slug',
            'Brand ETag Event',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );

        $profile = new OrganizerProfile($owner);
        $profile->setBrandLogoFilename('brand-etag.png');

        $storage->write('brand-etag.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
        ));

        $em->persist($owner);
        $em->persist($event);
        $em->persist($profile);
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/brand-etag-slug/brand-logo.png');
        self::assertResponseIsSuccessful();

        $etag = $client->getResponse()->headers->get('ETag');
        $this->assertNotNull($etag);

        $client->request(Request::METHOD_GET, '/e/brand-etag-slug/brand-logo.png', [], [], [
            'HTTP_IF_NONE_MATCH' => $etag,
        ]);

        self::assertResponseStatusCodeSame(304);
    }

    public function testNoSessionCookieEmittedForBrandLogoRequest(): void
    {
        $client = self::createClient();
        $c = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var FilesystemOperator $storage */
        $storage = $c->get('brand_logos_storage');

        $owner = new User('brand-session@example.com', 'Owner');
        $owner->setPassword('x');

        $event = new Event(
            'brand-session-slug',
            'Brand Session Event',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );

        $profile = new OrganizerProfile($owner);
        $profile->setBrandLogoFilename('brand-session.png');

        $storage->write('brand-session.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
        ));

        $em->persist($owner);
        $em->persist($event);
        $em->persist($profile);
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/brand-session-slug/brand-logo.png');

        self::assertResponseIsSuccessful();

        // The test env uses mock-file session storage (no DB rows). Anonymous public
        // routes must not touch the session at all — assert no Set-Cookie is emitted.
        $cookies = $client->getResponse()->headers->getCookies();
        $this->assertCount(0, $cookies, 'Brand-logo route must not emit any cookie.');
    }
}
