<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Photo;
use App\Entity\PhotoAttribute;
use App\Entity\PhotoAttributeType;
use App\Entity\PhotoStatus;
use App\Message\ExtractPhotoAttributes;
use App\Repository\BibSuppressionRepository;
use App\Repository\PhotoAttributeRepository;
use App\Repository\PhotoRepository;
use App\Service\Photo\AttributeExtractorClientInterface;
use App\Service\Photo\AttributeScore;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ExtractPhotoAttributesHandler
{
    public const float BIB_MIN_CONFIDENCE = 0.80;

    public function __construct(
        private PhotoRepository $photos,
        private PhotoAttributeRepository $attributes,
        private BibSuppressionRepository $suppressions,
        private AttributeExtractorClientInterface $client,
        private EntityManagerInterface $em,
        #[Autowire(service: 'photo_previews_storage')]
        private FilesystemOperator $previews,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExtractPhotoAttributes $message): void
    {
        $photo = $this->photos->find($message->photoId);
        if ($photo === null) {
            return;
        }

        // Idempotency: only tag photos that successfully reached Ready. A photo mid
        // re-ingest is Pending and will re-dispatch this message once it is Ready again.
        if ($photo->getStatus() !== PhotoStatus::Ready) {
            return;
        }

        $event = $photo->getEvent();
        $path = sprintf('event-%d/%d.jpg', (int) $event->getId(), (int) $photo->getId());

        try {
            $bytes = $this->previews->read($path);
        } catch (FilesystemException $filesystemException) {
            $this->logger->warning('Cannot read preview for attribute extraction.', [
                'photoId'   => $photo->getId(),
                'path'      => $path,
                'exception' => $filesystemException,
            ]);

            return;
        }

        $result = $this->client->extract($bytes);

        // Idempotent replace: clear prior tags, then re-insert from the fresh result.
        $this->attributes->deleteForPhoto($photo);

        foreach ($result->clothingColors as $attribute) {
            $this->persist($photo, PhotoAttributeType::ClothingColor, $attribute);
        }

        foreach ($result->clothingTypes as $attribute) {
            $this->persist($photo, PhotoAttributeType::ClothingType, $attribute);
        }

        foreach ($result->scenes as $attribute) {
            $this->persist($photo, PhotoAttributeType::Scene, $attribute);
        }

        foreach ($result->bibs as $attribute) {
            if (!$this->bibIsIndexable($photo, $attribute)) {
                continue;
            }

            $this->persist($photo, PhotoAttributeType::Bib, $attribute);
        }

        $this->em->flush();

        $photo->markAttributesExtracted();
        $this->em->flush();
    }

    private function bibIsIndexable(Photo $photo, AttributeScore $attribute): bool
    {
        $event = $photo->getEvent();

        if (!$event->isBibIndexingEnabled()) {
            return false;
        }

        if ($attribute->confidence < self::BIB_MIN_CONFIDENCE) {
            return false;
        }

        return !$this->suppressions->isSuppressed($event, $attribute->value);
    }

    private function persist(Photo $photo, PhotoAttributeType $type, AttributeScore $attribute): void
    {
        $this->em->persist(new PhotoAttribute($photo, $type, $attribute->value, $attribute->confidence));
    }
}
