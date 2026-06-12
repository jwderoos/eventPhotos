<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use Symfony\Component\HttpFoundation\Request;
use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PhotoServeTest extends WebTestCase
{
    public function testServesThumbForReadyPhoto(): void
    {
        $client = self::createClient();
        $c      = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var FilesystemOperator $thumbs */
        $thumbs = $c->get('photo_thumbs_storage');

        $owner = new User('o@example.test', 'O');
        $owner->setPassword('x');

        $em->persist($owner);
        $event = new Event(
            'e',
            'E',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $event->setTimezone('UTC');

        $em->persist($event);

        $photo = new Photo($event, str_repeat('a', 64), 'x.jpg', 100);
        $photo->markReady(new DateTimeImmutable('now', new DateTimeZone('UTC')), 100, 100);

        $em->persist($photo);
        $em->flush();

        $path = sprintf('event-%d/%d.jpg', (int) $event->getId(), (int) $photo->getId());
        $thumbs->write($path, 'thumb-bytes');

        try {
            $client->request(Request::METHOD_GET, sprintf('/e/%s/p/%d/thumb.jpg', $event->getSlug(), $photo->getId()));

            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'image/jpeg');
            $this->assertInstanceOf(StreamedResponse::class, $client->getResponse());
            $this->assertSame('thumb-bytes', $client->getInternalResponse()->getContent());
        } finally {
            $thumbs->delete($path);
        }
    }

    public function testReturns404ForPendingPhoto(): void
    {
        $client = self::createClient();
        $c      = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);

        $owner = new User('o2@example.test', 'O');
        $owner->setPassword('x');

        $em->persist($owner);
        $event = new Event(
            'e2',
            'E2',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $event->setTimezone('UTC');

        $em->persist($event);

        $photo = new Photo($event, str_repeat('b', 64), 'x.jpg', 100);
        $em->persist($photo);
        $em->flush();

        $client->request(Request::METHOD_GET, sprintf('/e/%s/p/%d/thumb.jpg', $event->getSlug(), $photo->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testReturns304WhenIfNoneMatchMatches(): void
    {
        $client = self::createClient();
        $c      = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var FilesystemOperator $thumbs */
        $thumbs = $c->get('photo_thumbs_storage');

        $owner = new User('o3@example.test', 'O');
        $owner->setPassword('x');

        $em->persist($owner);
        $event = new Event(
            'e3',
            'E3',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $event->setTimezone('UTC');

        $em->persist($event);

        $photo = new Photo($event, str_repeat('c', 64), 'x.jpg', 100);
        $photo->markReady(new DateTimeImmutable('now', new DateTimeZone('UTC')), 100, 100);

        $em->persist($photo);
        $em->flush();

        $path = sprintf('event-%d/%d.jpg', (int) $event->getId(), (int) $photo->getId());
        $thumbs->write($path, 'thumb-bytes');

        try {
            $url = sprintf('/e/%s/p/%d/thumb.jpg', $event->getSlug(), $photo->getId());

            $client->request(Request::METHOD_GET, $url);
            self::assertResponseIsSuccessful();
            $etag = $client->getResponse()->headers->get('ETag');
            $this->assertNotNull($etag);

            $client->request(Request::METHOD_GET, $url, [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
            self::assertResponseStatusCodeSame(304);
        } finally {
            $thumbs->delete($path);
        }
    }

    public function testReturns404WhenSlugDoesNotMatchPhotoEvent(): void
    {
        $client = self::createClient();
        $c      = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var FilesystemOperator $thumbs */
        $thumbs = $c->get('photo_thumbs_storage');

        $owner = new User('o4@example.test', 'O');
        $owner->setPassword('x');

        $em->persist($owner);

        $eventA = new Event(
            'e4a',
            'E4A',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $eventA->setTimezone('UTC');

        $em->persist($eventA);

        $eventB = new Event(
            'e4b',
            'E4B',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $eventB->setTimezone('UTC');

        $em->persist($eventB);

        $photo = new Photo($eventA, str_repeat('d', 64), 'x.jpg', 100);
        $photo->markReady(new DateTimeImmutable('now', new DateTimeZone('UTC')), 100, 100);

        $em->persist($photo);
        $em->flush();

        $path = sprintf('event-%d/%d.jpg', (int) $eventA->getId(), (int) $photo->getId());
        $thumbs->write($path, 'thumb-bytes');

        try {
            $client->request(Request::METHOD_GET, sprintf('/e/%s/p/%d/thumb.jpg', $eventA->getSlug(), $photo->getId()));
            self::assertResponseIsSuccessful();

            $client->request(Request::METHOD_GET, sprintf('/e/%s/p/%d/thumb.jpg', $eventB->getSlug(), $photo->getId()));
            self::assertResponseStatusCodeSame(404);
        } finally {
            $thumbs->delete($path);
        }
    }

    public function testOldUnscopedRouteIsGone(): void
    {
        $client = self::createClient();
        $c      = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var FilesystemOperator $thumbs */
        $thumbs = $c->get('photo_thumbs_storage');

        $owner = new User('o5@example.test', 'O');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            'e5',
            'E5',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $event->setTimezone('UTC');

        $em->persist($event);

        $photo = new Photo($event, str_repeat('e', 64), 'x.jpg', 100);
        $photo->markReady(new DateTimeImmutable('now', new DateTimeZone('UTC')), 100, 100);

        $em->persist($photo);
        $em->flush();

        $path = sprintf('event-%d/%d.jpg', (int) $event->getId(), (int) $photo->getId());
        $thumbs->write($path, 'thumb-bytes');

        try {
            $client->request(Request::METHOD_GET, sprintf('/p/%d/thumb.jpg', $photo->getId()));
            self::assertResponseStatusCodeSame(404);

            $client->request(Request::METHOD_GET, sprintf('/p/%d/preview.jpg', $photo->getId()));
            self::assertResponseStatusCodeSame(404);
        } finally {
            $thumbs->delete($path);
        }
    }
}
