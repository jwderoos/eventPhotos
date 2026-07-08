<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Event;

use App\Entity\Event;
use App\Entity\EventNotificationStatus;
use App\Entity\EventNotificationSubscription;
use App\Entity\Photo;
use App\Entity\User;
use App\Repository\EventNotificationSubscriptionRepository;
use App\Repository\PhotoRepository;
use App\Service\Event\Archive\SlugAlreadyExistsException;
use App\Service\Event\EventArchiveExporter;
use App\Service\Event\EventArchiveImporter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EventArchiveRoundtripTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private EventArchiveExporter $exporter;

    private EventArchiveImporter $importer;

    private FilesystemOperator $thumbs;

    private FilesystemOperator $previews;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var EventArchiveExporter $exporter */
        $exporter = $c->get(EventArchiveExporter::class);
        /** @var EventArchiveImporter $importer */
        $importer = $c->get(EventArchiveImporter::class);
        /** @var FilesystemOperator $thumbs */
        $thumbs = $c->get('photo_thumbs_storage');
        /** @var FilesystemOperator $previews */
        $previews = $c->get('photo_previews_storage');
        $this->em       = $em;
        $this->exporter = $exporter;
        $this->importer = $importer;
        $this->thumbs   = $thumbs;
        $this->previews = $previews;
    }

    public function testExportThenImportRecreatesEventUnderNewOwner(): void
    {
        $utc    = new DateTimeZone('UTC');
        $source = $this->makeUser('src@example.com');
        $target = $this->makeUser('dst@example.com');

        $event = new Event(
            'roundtrip-src',
            'Roundtrip',
            new DateTimeImmutable('2026-03-01 10:00:00', $utc),
            new DateTimeImmutable('2026-03-01 12:00:00', $utc),
            $source,
        );
        $event->markPublished(new DateTimeImmutable('2026-03-01 13:00:00', $utc));
        $event->enableNotifications();

        $this->em->persist($event);
        $this->em->flush();

        $photo = new Photo($event, str_repeat('b', 64), 'IMG_1.jpg', 111);
        $photo->markReady(new DateTimeImmutable('2026-03-01 11:00:00', $utc), 4000, 3000, 200_000);

        $this->em->persist($photo);
        $this->em->flush();

        $path = sprintf('event-%d/%d.jpg', (int) $event->getId(), (int) $photo->getId());
        $this->thumbs->write($path, "\xFF\xD8THUMBBYTES");
        $this->previews->write($path, "\xFF\xD8PREVIEWBYTES");

        $srcSub = EventNotificationSubscription::reconstituteForImport(
            $event,
            'fan@example.com',
            EventNotificationStatus::Confirmed,
            new DateTimeImmutable('2026-02-01 09:00:00', $utc),
            new DateTimeImmutable('2026-02-01 09:05:00', $utc),
            null,
            null,
        );
        $this->em->persist($srcSub);
        $this->em->flush();

        $srcToken = $srcSub->getUnsubscribeToken();

        // Export, then rename the source so the slug is free for import.
        $zip = $this->exporter->export($event);
        $event->setSlug('roundtrip-src-archived');
        $this->em->flush();

        $imported = $this->importer->import($zip, $target);
        @unlink($zip);

        $this->assertSame('roundtrip-src', $imported->getSlug());
        $this->assertSame($target, $imported->getOwner());
        $this->assertTrue($imported->isPublished());
        $this->assertTrue($imported->areNotificationsEnabled());

        /** @var PhotoRepository $photos */
        $photos = self::getContainer()->get(PhotoRepository::class);
        $this->assertSame(1, $photos->countReady($imported));

        $importedPath = sprintf(
            'event-%d/%d.jpg',
            (int) $imported->getId(),
            (int) $photos->findReadyInWindow(
                $imported,
                new DateTimeImmutable('2026-03-01 00:00:00', $utc),
                new DateTimeImmutable('2026-03-02 00:00:00', $utc),
            )[0]->getId(),
        );
        $this->assertSame("\xFF\xD8THUMBBYTES", $this->thumbs->read($importedPath));
        $this->assertSame("\xFF\xD8PREVIEWBYTES", $this->previews->read($importedPath));

        /** @var EventNotificationSubscriptionRepository $subs */
        $subs         = self::getContainer()->get(EventNotificationSubscriptionRepository::class);
        $importedSub  = $subs->findOneByEventAndEmail($imported, 'fan@example.com');
        $this->assertInstanceOf(EventNotificationSubscription::class, $importedSub);
        $this->assertSame(EventNotificationStatus::Confirmed, $importedSub->getStatus());
        $this->assertNotSame($srcToken, $importedSub->getUnsubscribeToken(), 'tokens must be regenerated');
    }

    public function testImportRefusesCollidingSlug(): void
    {
        $utc   = new DateTimeZone('UTC');
        $owner = $this->makeUser('coll@example.com');

        $event = new Event(
            'collide',
            'Collide',
            new DateTimeImmutable('2026-03-01 10:00:00', $utc),
            new DateTimeImmutable('2026-03-01 12:00:00', $utc),
            $owner,
        );
        $this->em->persist($event);
        $this->em->flush();

        $zip = $this->exporter->export($event);

        $this->expectException(SlugAlreadyExistsException::class);
        try {
            $this->importer->import($zip, $owner);
        } finally {
            @unlink($zip);
        }
    }

    private function makeUser(string $email): User
    {
        $user = new User($email, 'Name');
        $user->addRole('ROLE_ORGANIZER');

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
