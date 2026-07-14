<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Event;

use ZipArchive;
use App\Entity\Event;
use App\Entity\EventNotificationStatus;
use App\Entity\EventNotificationSubscription;
use App\Entity\Photo;
use App\Entity\User;
use App\Repository\EventNotificationSubscriptionRepository;
use App\Repository\PhotoRepository;
use App\Service\Event\Archive\InvalidArchiveException;
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

    private FilesystemOperator $originals;

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
        /** @var FilesystemOperator $originals */
        $originals = $c->get('photo_originals_storage');
        $this->em        = $em;
        $this->exporter   = $exporter;
        $this->importer   = $importer;
        $this->thumbs     = $thumbs;
        $this->previews   = $previews;
        $this->originals  = $originals;
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

    public function testExportIncludesOriginalsWhenRetained(): void
    {
        $utc   = new DateTimeZone('UTC');
        $owner = $this->makeUser('exp-orig@example.com');

        $event = new Event(
            'export-originals',
            'Export Originals',
            new DateTimeImmutable('2026-03-01 10:00:00', $utc),
            new DateTimeImmutable('2026-03-01 12:00:00', $utc),
            $owner,
        );
        $event->setRetainOriginals(true);

        $this->em->persist($event);
        $this->em->flush();

        $photo = new Photo($event, str_repeat('c', 64), 'IMG_O.jpg', 111);
        $photo->markReady(new DateTimeImmutable('2026-03-01 11:00:00', $utc), 4000, 3000, 200_000);

        $this->em->persist($photo);
        $this->em->flush();

        $path = sprintf('event-%d/%d.jpg', (int) $event->getId(), (int) $photo->getId());
        $this->originals->write($path, "\xFF\xD8ORIGINALBYTES");
        $this->thumbs->write($path, "\xFF\xD8THUMB");
        $this->previews->write($path, "\xFF\xD8PREVIEW");

        $zip = $this->exporter->export($event);

        $za = new ZipArchive();
        $this->assertTrue($za->open($zip));
        $original = $za->getFromName('photos/' . str_repeat('c', 64) . '.original.jpg');
        $manifest = $za->getFromName('manifest.json');
        $za->close();
        @unlink($zip);

        $this->assertSame("\xFF\xD8ORIGINALBYTES", $original);
        $this->assertStringContainsString('"retainOriginals": true', (string) $manifest);
    }

    public function testImportRestoresOriginalsAndFlag(): void
    {
        $utc   = new DateTimeZone('UTC');
        $owner = $this->makeUser('imp-orig@example.com');

        $event = new Event(
            'import-originals-src',
            'Import Originals',
            new DateTimeImmutable('2026-03-01 10:00:00', $utc),
            new DateTimeImmutable('2026-03-01 12:00:00', $utc),
            $owner,
        );
        $event->setRetainOriginals(true);

        $this->em->persist($event);
        $this->em->flush();

        $photo = new Photo($event, str_repeat('d', 64), 'IMG_D.jpg', 111);
        $photo->markReady(new DateTimeImmutable('2026-03-01 11:00:00', $utc), 4000, 3000, 200_000);

        $this->em->persist($photo);
        $this->em->flush();

        $path = sprintf('event-%d/%d.jpg', (int) $event->getId(), (int) $photo->getId());
        $this->originals->write($path, "\xFF\xD8ORIGINALBYTES");
        $this->thumbs->write($path, "\xFF\xD8THUMB");
        $this->previews->write($path, "\xFF\xD8PREVIEW");

        $zip = $this->exporter->export($event);
        $event->setSlug('import-originals-src-archived');
        $this->em->flush();

        $imported = $this->importer->import($zip, $owner);
        @unlink($zip);

        $this->assertTrue($imported->isRetainOriginals());

        /** @var PhotoRepository $photos */
        $photos      = self::getContainer()->get(PhotoRepository::class);
        $importedPhoto = $photos->findReadyInWindow(
            $imported,
            new DateTimeImmutable('2026-03-01 00:00:00', $utc),
            new DateTimeImmutable('2026-03-02 00:00:00', $utc),
        )[0];
        $importedPath = sprintf('event-%d/%d.jpg', (int) $imported->getId(), (int) $importedPhoto->getId());
        $this->assertSame("\xFF\xD8ORIGINALBYTES", $this->originals->read($importedPath));
    }

    public function testImportFailsWhenRetainedOriginalMissing(): void
    {
        $owner = $this->makeUser('imp-missing@example.com');

        // Build an archive that CLAIMS retainOriginals but omits the .original.jpg entry.
        $manifest = [
            'format'  => 'eventphotos.event-export',
            'version' => 1,
            'exportedAt'     => '2026-03-01T10:00:00+00:00',
            'sourceInstance' => '',
            'event' => [
                'name' => 'Broken', 'slug' => 'broken-archive', 'description' => null,
                'timezone' => 'UTC', 'startsAt' => '2026-03-01T10:00:00+00:00',
                'endsAt' => '2026-03-01T12:00:00+00:00', 'publishedAt' => null,
                'notificationsEnabled' => false,
                'style' => [
                    'fontColor' => null, 'backgroundColor' => null, 'buttonColor' => null, 'glowEnabled' => null,
                ],
                'logo' => null,
                'retainOriginals' => true,
            ],
            'photos' => [[
                'contentHash' => str_repeat('e', 64), 'originalFilename' => 'x.jpg',
                'byteSize' => 100, 'width' => 10, 'height' => 10,
                'takenAt' => '2026-03-01T11:00:00+00:00', 'derivativeBytes' => 50,
                'createdAt' => '2026-03-01T10:30:00+00:00',
            ]],
            'subscriptions' => [],
            'skippedPhotos' => 0,
        ];

        $zipPath = tempnam(sys_get_temp_dir(), 'evt-broken-');
        $za = new ZipArchive();
        $za->open($zipPath, ZipArchive::OVERWRITE);
        $za->addFromString('manifest.json', (string) json_encode($manifest));

        $hash = str_repeat('e', 64);
        $za->addFromString('photos/' . $hash . '.thumb.jpg', "\xFF\xD8THUMB");
        $za->addFromString('photos/' . $hash . '.preview.jpg', "\xFF\xD8PREVIEW");
        // NOTE: no .original.jpg entry.
        $za->close();

        try {
            $this->expectException(InvalidArchiveException::class);
            $this->importer->import($zipPath, $owner);
        } finally {
            @unlink($zipPath);
        }
    }

    public function testImportOldArchiveWithoutRetainFlagSucceedsWithFlagOff(): void
    {
        $utc   = new DateTimeZone('UTC');
        $owner = $this->makeUser('imp-old@example.com');

        // Simulate a PRE-#110 export: NO retainOriginals key, NO .original.jpg entry.
        $manifest = [
            'format'  => 'eventphotos.event-export',
            'version' => 1,
            'exportedAt'     => '2026-03-01T10:00:00+00:00',
            'sourceInstance' => '',
            'event' => [
                'name' => 'Old Archive', 'slug' => 'old-archive', 'description' => null,
                'timezone' => 'UTC', 'startsAt' => '2026-03-01T10:00:00+00:00',
                'endsAt' => '2026-03-01T12:00:00+00:00', 'publishedAt' => null,
                'notificationsEnabled' => false,
                'style' => [
                    'fontColor' => null, 'backgroundColor' => null, 'buttonColor' => null, 'glowEnabled' => null,
                ],
                'logo' => null,
                // NOTE: no retainOriginals key at all.
            ],
            'photos' => [[
                'contentHash' => str_repeat('f', 64), 'originalFilename' => 'y.jpg',
                'byteSize' => 100, 'width' => 10, 'height' => 10,
                'takenAt' => '2026-03-01T11:00:00+00:00', 'derivativeBytes' => 50,
                'createdAt' => '2026-03-01T10:30:00+00:00',
            ]],
            'subscriptions' => [],
            'skippedPhotos' => 0,
        ];

        $zipPath = tempnam(sys_get_temp_dir(), 'evt-old-');
        $za = new ZipArchive();
        $za->open($zipPath, ZipArchive::OVERWRITE);
        $za->addFromString('manifest.json', (string) json_encode($manifest));

        $hash = str_repeat('f', 64);
        $za->addFromString('photos/' . $hash . '.thumb.jpg', "\xFF\xD8OLDTHUMB");
        $za->addFromString('photos/' . $hash . '.preview.jpg', "\xFF\xD8OLDPREVIEW");
        // NOTE: no .original.jpg entry (pre-#110 archive never had one).
        $za->close();

        $imported = $this->importer->import($zipPath, $owner);
        @unlink($zipPath);

        $this->assertFalse($imported->isRetainOriginals());

        /** @var PhotoRepository $photos */
        $photos = self::getContainer()->get(PhotoRepository::class);
        $this->assertSame(1, $photos->countReady($imported));

        $importedPhoto = $photos->findReadyInWindow(
            $imported,
            new DateTimeImmutable('2026-03-01 00:00:00', $utc),
            new DateTimeImmutable('2026-03-02 00:00:00', $utc),
        )[0];
        $importedPath = sprintf('event-%d/%d.jpg', (int) $imported->getId(), (int) $importedPhoto->getId());

        $this->assertSame("\xFF\xD8OLDTHUMB", $this->thumbs->read($importedPath));
        $this->assertSame("\xFF\xD8OLDPREVIEW", $this->previews->read($importedPath));
        $this->assertFalse(
            $this->originals->fileExists($importedPath),
            'No original must be written when the archive predates the retain flag.',
        );
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
