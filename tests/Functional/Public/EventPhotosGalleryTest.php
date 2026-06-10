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

        $event = new Event('gallery', 'Gallery', new DateTimeImmutable('2026-06-10'), $owner);
        $event->setTimezone('UTC');

        $em->persist($event);

        $inside = new Photo($event, str_repeat('a', 64), 'a.jpg', 100);
        $inside->markReady(new DateTimeImmutable('2026-06-10 12:00:00', new DateTimeZone('UTC')), 100, 100);

        $em->persist($inside);

        $outside = new Photo($event, str_repeat('b', 64), 'b.jpg', 100);
        $outside->markReady(new DateTimeImmutable('2026-06-10 18:00:00', new DateTimeZone('UTC')), 100, 100);

        $em->persist($outside);

        $em->flush();

        $client->request(Request::METHOD_GET, '/e/gallery/photos?t=2026-06-10T12:00:00%2B00:00&w=30');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString(sprintf('/p/%d/thumb.jpg', $inside->getId()), $content);
        $this->assertStringNotContainsString(sprintf('/p/%d/thumb.jpg', $outside->getId()), $content);
    }

    public function testHidesPendingPhotos(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('g2@example.test', 'G');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event('g2', 'G2', new DateTimeImmutable('2026-06-10'), $owner);
        $event->setTimezone('UTC');

        $em->persist($event);

        $pending = new Photo($event, str_repeat('c', 64), 'c.jpg', 100);
        $em->persist($pending);
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/g2/photos?t=2026-06-10T12:00:00%2B00:00&w=720');

        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString(
            sprintf('/p/%d/thumb.jpg', $pending->getId()),
            (string) $client->getResponse()->getContent(),
        );
    }
}
