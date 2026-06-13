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
}
