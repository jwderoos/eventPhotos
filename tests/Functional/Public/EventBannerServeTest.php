<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EventBannerServeTest extends WebTestCase
{
    private function persistEventWithBanner(
        EntityManagerInterface $em,
        FilesystemOperator $storage,
        string $slug,
        bool $withBanner,
    ): Event {
        $owner = new User($slug . '@example.com', 'Owner');
        $owner->setPassword('x');

        $event = new Event(
            $slug,
            'Banner Serve',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );

        if ($withBanner) {
            $filename = 'event-serve.jpg';
            $storage->write($filename, (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/photos/bigger.jpg'));
            $event->setBannerFilename($filename);
            $event->setBannerUpdatedAt(new DateTimeImmutable('2026-07-07 12:00'));
        }

        $em->persist($owner);
        $em->persist($event);
        $em->flush();

        return $event;
    }

    public function testServesBannerWithImmutableCacheHeaders(): void
    {
        $client = self::createClient();
        $c = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var FilesystemOperator $storage */
        $storage = $c->get('event_banners_storage');

        $this->persistEventWithBanner($em, $storage, 'banner-serve-yes', true);

        $client->request(Request::METHOD_GET, '/e/banner-serve-yes/banner.jpg');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');
        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        $this->assertStringContainsString('immutable', $cacheControl);
        $this->assertStringContainsString('max-age=31536000', $cacheControl);
        $this->assertNotNull($client->getResponse()->headers->get('ETag'));
    }

    public function testReturns404WhenNoBanner(): void
    {
        $client = self::createClient();
        $c = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var FilesystemOperator $storage */
        $storage = $c->get('event_banners_storage');

        $this->persistEventWithBanner($em, $storage, 'banner-serve-no', false);

        $client->request(Request::METHOD_GET, '/e/banner-serve-no/banner.jpg');

        self::assertResponseStatusCodeSame(404);
    }
}
