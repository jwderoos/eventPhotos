<?php

declare(strict_types=1);

namespace App\MessageHandler;

use RuntimeException;
use App\Entity\PhotoStatus;
use App\Message\ProcessPhoto;
use App\Repository\PhotoRepository;
use App\Service\Photo\DerivativeGenerator;
use App\Service\Photo\ExifReader;
use App\Service\Photo\PhotoRejected;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessPhotoHandler
{
    public function __construct(
        private PhotoRepository $photos,
        private EntityManagerInterface $em,
        private ExifReader $exifReader,
        private DerivativeGenerator $derivatives,
        #[Autowire(service: 'photo_originals_storage')]
        private FilesystemOperator $originals,
    ) {
    }

    public function __invoke(ProcessPhoto $message): void
    {
        $photo = $this->photos->find($message->photoId);
        if ($photo === null) {
            return;
        }

        if ($photo->getStatus() !== PhotoStatus::Pending) {
            return;
        }

        $event = $photo->getEvent();
        $path = sprintf('event-%d/%d.jpg', (int) $event->getId(), (int) $photo->getId());

        try {
            $tmpFile = $this->stageToTmp($path);
            try {
                $takenAt = $this->exifReader->readTakenAt(
                    $tmpFile,
                    new DateTimeZone($event->getTimezone()),
                );
            } finally {
                @unlink($tmpFile);
            }

            [$width, $height] = $this->derivatives->generate($path);
            $photo->markReady($takenAt, $width, $height);
            $this->em->flush();
        } catch (PhotoRejected $photoRejected) {
            $photo->markFailed($photoRejected->getMessage());
            $this->em->flush();
        }
    }

    /**
     * exif_read_data needs a real file path. Stream the Flysystem object to a temp file.
     */
    private function stageToTmp(string $path): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'photo-exif-');
        if ($tmp === false) {
            throw new RuntimeException('Could not create temp file for EXIF read.');
        }

        file_put_contents($tmp, $this->originals->read($path));

        return $tmp;
    }
}
