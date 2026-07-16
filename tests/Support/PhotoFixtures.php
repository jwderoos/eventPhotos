<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\PhotoAttribute;
use App\Entity\PhotoAttributeType;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Shared entity-construction helpers for repository/integration tests that
 * need an Event + Ready Photo + PhotoAttribute graph. Centralising this
 * avoids duplicating the same fixture boilerplate (and the resulting phpcpd
 * violations) across the gallery-search test suites.
 */
final class PhotoFixtures
{
    public static function event(
        EntityManagerInterface $em,
        string $slug = 'demo',
        bool $bibIndexing = false,
    ): Event {
        $owner = new User(sprintf('owner-%s@example.test', $slug), 'O');
        $owner->setPassword('x');

        $em->persist($owner);

        $event = new Event(
            $slug,
            ucfirst($slug),
            new DateTimeImmutable('2026-07-15 09:00'),
            new DateTimeImmutable('2026-07-15 18:00'),
            $owner,
        );
        $event->setTimezone('UTC');

        if ($bibIndexing) {
            $event->enableBibIndexing();
        }

        $em->persist($event);
        $em->flush();

        return $event;
    }

    public static function readyPhoto(EntityManagerInterface $em, Event $event, string $takenAt): Photo
    {
        $photo = new Photo(
            event: $event,
            contentHash: bin2hex(random_bytes(32)),
            originalFilename: 'x.jpg',
            byteSize: 100,
        );
        $photo->markReady(new DateTimeImmutable($takenAt, new DateTimeZone('UTC')), 100, 100, 1024);

        $em->persist($photo);

        return $photo;
    }

    public static function tag(EntityManagerInterface $em, Photo $photo, PhotoAttributeType $type, string $value): void
    {
        $em->persist(new PhotoAttribute($photo, $type, $value));
    }

    public static function tagColour(EntityManagerInterface $em, Photo $photo, string $value): void
    {
        self::tag($em, $photo, PhotoAttributeType::ClothingColor, $value);
    }

    public static function tagBib(EntityManagerInterface $em, Photo $photo, string $value): void
    {
        self::tag($em, $photo, PhotoAttributeType::Bib, $value);
    }
}
