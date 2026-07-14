<?php

declare(strict_types=1);

namespace App\Service\Event;

use App\Entity\Event;
use App\Entity\EventNotificationStatus;
use App\Entity\EventNotificationSubscription;
use App\Entity\Photo;
use App\Entity\User;
use App\Repository\EventRepository;
use App\Service\Event\Archive\EventArchiveManifest;
use App\Service\Event\Archive\InvalidArchiveException;
use App\Service\Event\Archive\ManifestPhoto;
use App\Service\Event\Archive\ManifestSubscription;
use App\Service\Event\Archive\SlugAlreadyExistsException;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Random\RandomException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;
use ZipArchive;

final readonly class EventArchiveImporter
{
    private const int LOGO_NAME_BYTES = 16;

    private const string JPEG_SOI = "\xFF\xD8";

    public function __construct(
        private EntityManagerInterface $em,
        private EventRepository $events,
        #[Autowire(service: 'photo_thumbs_storage')]
        private FilesystemOperator $thumbs,
        #[Autowire(service: 'photo_previews_storage')]
        private FilesystemOperator $previews,
        #[Autowire(service: 'event_logos_storage')]
        private FilesystemOperator $logos,
        #[Autowire(service: 'photo_originals_storage')]
        private FilesystemOperator $originals,
    ) {
    }

    /**
     * @throws InvalidArchiveException
     * @throws SlugAlreadyExistsException
     */
    public function import(string $zipPath, User $owner): Event
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new InvalidArchiveException('Upload is not a readable ZIP archive.');
        }

        try {
            $manifestJson = $zip->getFromName('manifest.json');
            if ($manifestJson === false) {
                throw new InvalidArchiveException('Archive is missing manifest.json.');
            }

            $manifest = EventArchiveManifest::fromJson($manifestJson);

            if ($this->events->findOneBySlug($manifest->event->slug) instanceof Event) {
                throw new SlugAlreadyExistsException($manifest->event->slug);
            }

            return $this->reconstitute($manifest, $zip, $owner);
        } finally {
            $zip->close();
        }
    }

    /**
     * @throws InvalidArchiveException
     */
    private function reconstitute(EventArchiveManifest $manifest, ZipArchive $zip, User $owner): Event
    {
        $utc   = new DateTimeZone('UTC');
        $me    = $manifest->event;
        $event = new Event(
            $me->slug,
            $me->name,
            new DateTimeImmutable($me->startsAt),
            new DateTimeImmutable($me->endsAt),
            $owner,
        );
        $event->setDescription($me->description);
        $event->setTimezone($me->timezone);
        $event->getStyle()->setFontColor($me->fontColor);
        $event->getStyle()->setBackgroundColor($me->backgroundColor);
        $event->getStyle()->setButtonColor($me->buttonColor);
        $event->getStyle()->setGlowEnabled($me->glowEnabled);

        if ($me->publishedAt !== null) {
            $event->markPublished(new DateTimeImmutable($me->publishedAt));
        }

        if ($me->notificationsEnabled) {
            $event->enableNotifications();
        }

        $event->setRetainOriginals($me->retainOriginals);

        /** @var list<array{FilesystemOperator, string}> $written */
        $written = [];

        $this->em->beginTransaction();

        try {
            $this->em->persist($event);
            $this->em->flush(); // assigns the event id

            if ($me->logoFilename !== null) {
                $event->setLogoFilename($this->writeLogo($zip, $me->logoFilename, $written));
            }

            foreach ($manifest->photos as $manifestPhoto) {
                $this->reconstitutePhoto($event, $manifestPhoto, $zip, $written);
            }

            foreach ($manifest->subscriptions as $manifestSub) {
                $this->em->persist($this->reconstituteSubscription($event, $manifestSub, $utc));
            }

            $this->em->flush();
            $this->em->commit();

            return $event;
        } catch (Throwable $throwable) {
            $this->em->rollback();
            foreach ($written as [$storage, $path]) {
                try {
                    $storage->delete($path);
                } catch (Throwable) {
                    // best-effort cleanup
                }
            }

            throw $throwable;
        }
    }

    /**
     * @param list<array{FilesystemOperator, string}> $written
     *
     * @throws InvalidArchiveException
     */
    private function reconstitutePhoto(Event $event, ManifestPhoto $mp, ZipArchive $zip, array &$written): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $mp->contentHash) !== 1) {
            throw new InvalidArchiveException('Photo content hash is malformed.');
        }

        $thumbBytes   = $this->readJpeg($zip, 'photos/' . $mp->contentHash . '.thumb.jpg');
        $previewBytes = $this->readJpeg($zip, 'photos/' . $mp->contentHash . '.preview.jpg');

        $photo = new Photo($event, $mp->contentHash, $mp->originalFilename, $mp->byteSize);
        $takenAt = $mp->takenAt !== null
            ? new DateTimeImmutable($mp->takenAt)
            : new DateTimeImmutable($mp->createdAt);
        $photo->markReady($takenAt, $mp->width, $mp->height, $mp->derivativeBytes);

        $this->em->persist($photo);
        $this->em->flush(); // assigns the photo id for the storage path

        $path = sprintf('event-%d/%d.jpg', (int) $event->getId(), (int) $photo->getId());
        $this->thumbs->write($path, $thumbBytes);
        $written[] = [$this->thumbs, $path];
        $this->previews->write($path, $previewBytes);
        $written[] = [$this->previews, $path];

        if ($event->isRetainOriginals()) {
            $originalBytes = $this->readJpeg($zip, 'photos/' . $mp->contentHash . '.original.jpg');
            $this->originals->write($path, $originalBytes);
            $written[] = [$this->originals, $path];
        }
    }

    private function reconstituteSubscription(
        Event $event,
        ManifestSubscription $ms,
        DateTimeZone $utc,
    ): EventNotificationSubscription {
        $status = EventNotificationStatus::tryFrom($ms->status) ?? EventNotificationStatus::Pending;

        return EventNotificationSubscription::reconstituteForImport(
            $event,
            $ms->email,
            $status,
            new DateTimeImmutable($ms->createdAt, $utc),
            $ms->confirmedAt !== null ? new DateTimeImmutable($ms->confirmedAt, $utc) : null,
            $ms->unsubscribedAt !== null ? new DateTimeImmutable($ms->unsubscribedAt, $utc) : null,
            $ms->notifiedAt !== null ? new DateTimeImmutable($ms->notifiedAt, $utc) : null,
        );
    }

    /**
     * @param list<array{FilesystemOperator, string}> $written
     *
     * @throws InvalidArchiveException
     */
    private function writeLogo(ZipArchive $zip, string $originalFilename, array &$written): string
    {
        $bytes = $zip->getFromName('images/logo/' . basename($originalFilename));
        if ($bytes === false) {
            throw new InvalidArchiveException('Archive manifest references a logo that is missing.');
        }

        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
            $ext = 'png';
        }

        try {
            $name = bin2hex(random_bytes(self::LOGO_NAME_BYTES)) . '.' . $ext;
        } catch (RandomException $randomException) {
            throw new InvalidArchiveException('Could not generate a logo filename.', 0, $randomException);
        }

        $this->logos->write($name, $bytes);
        $written[] = [$this->logos, $name];

        return $name;
    }

    /**
     * @throws InvalidArchiveException
     */
    private function readJpeg(ZipArchive $zip, string $entry): string
    {
        $bytes = $zip->getFromName($entry);
        if ($bytes === false || !str_starts_with($bytes, self::JPEG_SOI)) {
            throw new InvalidArchiveException(sprintf('Archive entry "%s" is missing or not a JPEG.', $entry));
        }

        return $bytes;
    }
}
