<?php

declare(strict_types=1);

namespace App\Service\Event;

use App\Entity\Event;
use App\Entity\EventNotificationSubscription;
use App\Entity\Photo;
use App\Entity\PhotoStatus;
use App\Repository\EventNotificationSubscriptionRepository;
use App\Repository\PhotoRepository;
use App\Service\Event\Archive\EventArchiveManifest;
use App\Service\Event\Archive\ManifestEvent;
use App\Service\Event\Archive\ManifestPhoto;
use App\Service\Event\Archive\ManifestSubscription;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use League\Flysystem\FilesystemOperator;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use ZipArchive;

final readonly class EventArchiveExporter
{
    private const string TMP_PREFIX = 'evt-export-';

    public function __construct(
        private PhotoRepository $photos,
        private EventNotificationSubscriptionRepository $subscriptions,
        #[Autowire(service: 'photo_thumbs_storage')]
        private FilesystemOperator $thumbs,
        #[Autowire(service: 'photo_previews_storage')]
        private FilesystemOperator $previews,
        #[Autowire(service: 'photo_originals_storage')]
        private FilesystemOperator $originals,
        #[Autowire(service: 'event_logos_storage')]
        private FilesystemOperator $logos,
        #[Autowire('%env(default::DEFAULT_URI)%')]
        private string $sourceInstance = '',
    ) {
    }

    public function export(Event $event): string
    {
        /** @var list<Photo> $allPhotos */
        $allPhotos = $this->photos->findBy(['event' => $event], ['id' => 'ASC']);
        $ready     = array_values(array_filter(
            $allPhotos,
            static fn (Photo $p): bool => $p->getStatus() === PhotoStatus::Ready,
        ));

        $zipPath = tempnam(sys_get_temp_dir(), self::TMP_PREFIX);
        if ($zipPath === false) {
            throw new RuntimeException('Could not allocate a temp file for the export.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open the export archive for writing.');
        }

        $eventId       = (int) $event->getId();
        $manifestPhotos = [];

        foreach ($ready as $photo) {
            $photoId = (int) $photo->getId();
            $path    = sprintf('event-%d/%d.jpg', $eventId, $photoId);
            $hash    = $photo->getContentHash();

            $zip->addFromString('photos/' . $hash . '.thumb.jpg', $this->thumbs->read($path));
            $zip->addFromString('photos/' . $hash . '.preview.jpg', $this->previews->read($path));

            if ($event->isRetainOriginals()) {
                $zip->addFromString('photos/' . $hash . '.original.jpg', $this->originals->read($path));
            }

            $manifestPhotos[] = new ManifestPhoto(
                $hash,
                $photo->getOriginalFilename(),
                $photo->getByteSize(),
                $photo->getWidth() ?? 0,
                $photo->getHeight() ?? 0,
                self::iso($photo->getTakenAt()),
                $photo->getDerivativeBytes() ?? 0,
                self::iso($photo->getCreatedAt()) ?? '',
            );
        }

        $logoFilename = $event->getLogoFilename();
        if ($logoFilename !== null) {
            $zip->addFromString('images/logo/' . basename($logoFilename), $this->logos->read($logoFilename));
        }

        $manifest = new EventArchiveManifest(
            self::iso(new DateTimeImmutable('now', new DateTimeZone('UTC'))) ?? '',
            $this->sourceInstance,
            $this->buildManifestEvent($event, $logoFilename),
            $manifestPhotos,
            $this->buildManifestSubscriptions($event),
            count($allPhotos) - count($ready),
        );

        $zip->addFromString('manifest.json', $manifest->toJson());
        $zip->close();

        return $zipPath;
    }

    private function buildManifestEvent(Event $event, ?string $logoFilename): ManifestEvent
    {
        $style = $event->getStyle();

        return new ManifestEvent(
            $event->getName(),
            $event->getSlug(),
            $event->getDescription(),
            $event->getTimezone(),
            self::iso($event->getStartsAt()) ?? '',
            self::iso($event->getEndsAt()) ?? '',
            self::iso($event->getPublishedAt()),
            $event->areNotificationsEnabled(),
            $style->getFontColor(),
            $style->getBackgroundColor(),
            $style->getButtonColor(),
            $style->getGlowEnabled(),
            $logoFilename,
            $event->isRetainOriginals(),
        );
    }

    /**
     * @return list<ManifestSubscription>
     */
    private function buildManifestSubscriptions(Event $event): array
    {
        /** @var list<EventNotificationSubscription> $subs */
        $subs = $this->subscriptions->findBy(['event' => $event], ['id' => 'ASC']);

        return array_map(static fn (EventNotificationSubscription $s): ManifestSubscription => new ManifestSubscription(
            $s->getEmail(),
            $s->getStatus()->value,
            self::iso($s->getConfirmedAt()),
            self::iso($s->getUnsubscribedAt()),
            self::iso($s->getNotifiedAt()),
            self::iso($s->getCreatedAt()) ?? '',
        ), $subs);
    }

    private static function iso(?DateTimeInterface $value): ?string
    {
        return $value?->format(DateTimeInterface::ATOM);
    }
}
