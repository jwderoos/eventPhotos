<?php

declare(strict_types=1);

namespace App\Service\Event;

use App\Entity\Event;
use App\Service\Image\GdImageResizer;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class BannerUploader
{
    private const int LONG_EDGE = 1600;

    private const int QUALITY = 85;

    public function __construct(
        private GdImageResizer $resizer,
        #[Autowire(service: 'event_banners_storage')]
        private FilesystemOperator $banners,
        private ClockInterface $clock,
    ) {
    }

    public function upload(Event $event, string $bytes): void
    {
        $image  = $this->resizer->decode($bytes);
        $scaled = $this->resizer->scaleTo($image, imagesx($image), imagesy($image), self::LONG_EDGE);
        $jpeg   = $this->resizer->encode($scaled, self::QUALITY);

        $filename = $this->filename($event);
        $this->banners->write($filename, $jpeg);

        $event->setBannerFilename($filename);
        $event->setBannerUpdatedAt($this->clock->now());
    }

    public function remove(Event $event): void
    {
        $filename = $event->getBannerFilename();
        if ($filename !== null) {
            try {
                $this->banners->delete($filename);
            } catch (FilesystemException) {
                // Already gone — nothing to clean up.
            }
        }

        $event->setBannerFilename(null);
        $event->setBannerUpdatedAt(null);
    }

    private function filename(Event $event): string
    {
        return sprintf('event-%d.jpg', (int) $event->getId());
    }
}
