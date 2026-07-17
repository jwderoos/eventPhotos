<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\BibSuppression;
use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\PhotoAttribute;
use App\Entity\PhotoAttributeType;
use App\Entity\User;
use App\Message\ExtractPhotoAttributes;
use App\MessageHandler\ExtractPhotoAttributesHandler;
use App\Repository\PhotoAttributeRepository;
use App\Service\Photo\AttributeScore;
use App\Service\Photo\ExtractedAttributes;
use App\Tests\Fake\FakeAttributeExtractorClient;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExtractPhotoAttributesHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private FilesystemOperator $previews;

    private FakeAttributeExtractorClient $client;

    private PhotoAttributeRepository $attributes;

    private ExtractPhotoAttributesHandler $handler;

    private Event $event;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var FilesystemOperator $previews */
        $previews = $c->get('photo_previews_storage');
        /** @var FakeAttributeExtractorClient $client */
        $client = $c->get(FakeAttributeExtractorClient::class);
        /** @var PhotoAttributeRepository $attributes */
        $attributes = $c->get(PhotoAttributeRepository::class);
        /** @var ExtractPhotoAttributesHandler $handler */
        $handler = $c->get(ExtractPhotoAttributesHandler::class);

        $this->em = $em;
        $this->previews = $previews;
        $this->client = $client;
        $this->attributes = $attributes;
        $this->handler = $handler;

        $owner = new User('o@example.test', 'O');
        $owner->setPassword('x');

        $this->em->persist($owner);

        $this->event = new Event(
            'demo',
            'Demo',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $this->em->persist($this->event);
        $this->em->flush();
    }

    private function seedReadyPhoto(string $hash): Photo
    {
        $photo = new Photo($this->event, str_pad($hash, 64, '0'), 'p.jpg', 1000);
        $this->em->persist($photo);
        $this->em->flush();

        $photo->markReady(new DateTimeImmutable('2026-06-10 10:30'), 1600, 1067, 500);
        $this->em->flush();

        // The handler reads the preview derivative; content is irrelevant to the fake client.
        $path = sprintf('event-%d/%d.jpg', $this->event->getId(), $photo->getId());
        $this->previews->write($path, 'fake-preview-bytes');

        return $photo;
    }

    /**
     * @param list<array{string,float}> $colors
     * @param list<array{string,float}> $bibs
     */
    private function response(array $colors = [], array $bibs = []): ExtractedAttributes
    {
        return new ExtractedAttributes(
            clothingColors: array_map(
                static fn (array $c): AttributeScore => new AttributeScore($c[0], $c[1]),
                $colors,
            ),
            clothingTypes: [],
            scenes: [],
            bibs: array_map(
                static fn (array $b): AttributeScore => new AttributeScore($b[0], $b[1]),
                $bibs,
            ),
        );
    }

    /**
     * @return list<string> values of attributes of the given type for the photo
     */
    private function valuesOfType(Photo $photo, PhotoAttributeType $type): array
    {
        return array_values(array_map(
            static fn (PhotoAttribute $a): string => $a->getValue(),
            array_filter(
                $this->attributes->findForPhoto($photo),
                static fn (PhotoAttribute $a): bool => $a->getType() === $type,
            ),
        ));
    }

    public function testWritesClothingTagsUnconditionally(): void
    {
        $photo = $this->seedReadyPhoto('aa');
        $this->client->setNext($this->response(colors: [['orange', 0.92]]));

        ($this->handler)(new ExtractPhotoAttributes($photo->getId() ?? 0));

        $this->assertSame(['orange'], $this->valuesOfType($photo, PhotoAttributeType::ClothingColor));
    }

    public function testReRunReplacesExistingTags(): void
    {
        $photo = $this->seedReadyPhoto('bb');

        $this->client->setNext($this->response(colors: [['orange', 0.9]]));
        ($this->handler)(new ExtractPhotoAttributes($photo->getId() ?? 0));

        $this->client->setNext($this->response(colors: [['blue', 0.9]]));
        ($this->handler)(new ExtractPhotoAttributes($photo->getId() ?? 0));

        $this->assertSame(['blue'], $this->valuesOfType($photo, PhotoAttributeType::ClothingColor));
    }

    public function testBibSkippedWhenToggleOff(): void
    {
        $photo = $this->seedReadyPhoto('cc'); // event.bibIndexingEnabled defaults to false
        $this->client->setNext($this->response(bibs: [['1423', 0.99]]));

        ($this->handler)(new ExtractPhotoAttributes($photo->getId() ?? 0));

        $this->assertSame([], $this->valuesOfType($photo, PhotoAttributeType::Bib));
    }

    public function testBibSkippedBelowConfidenceThreshold(): void
    {
        $this->event->enableBibIndexing();
        $this->em->flush();
        $photo = $this->seedReadyPhoto('dd');
        $this->client->setNext($this->response(bibs: [['1423', 0.5]]));

        ($this->handler)(new ExtractPhotoAttributes($photo->getId() ?? 0));

        $this->assertSame([], $this->valuesOfType($photo, PhotoAttributeType::Bib));
    }

    public function testBibSkippedWhenSuppressed(): void
    {
        $this->event->enableBibIndexing();
        $this->em->persist(new BibSuppression($this->event, '1423'));
        $this->em->flush();

        $photo = $this->seedReadyPhoto('ee');
        $this->client->setNext($this->response(bibs: [['1423', 0.99]]));

        ($this->handler)(new ExtractPhotoAttributes($photo->getId() ?? 0));

        $this->assertSame([], $this->valuesOfType($photo, PhotoAttributeType::Bib));
    }

    public function testBibWrittenWhenAllConditionsMet(): void
    {
        $this->event->enableBibIndexing();
        $this->em->flush();
        $photo = $this->seedReadyPhoto('ff');
        $this->client->setNext($this->response(bibs: [['1423', 0.99]]));

        ($this->handler)(new ExtractPhotoAttributes($photo->getId() ?? 0));

        $this->assertSame(['1423'], $this->valuesOfType($photo, PhotoAttributeType::Bib));
    }

    public function testSuppressionSurvivesReingest(): void
    {
        $this->event->enableBibIndexing();
        $photo = $this->seedReadyPhoto('a1');
        $this->client->setNext($this->response(bibs: [['1423', 0.99]]));

        // First extraction writes the bib.
        ($this->handler)(new ExtractPhotoAttributes($photo->getId() ?? 0));
        $this->assertSame(['1423'], $this->valuesOfType($photo, PhotoAttributeType::Bib));

        // Organizer de-indexes: delete bib tags + suppress (Plan A behaviour, simulated here).
        $this->attributes->deleteForPhoto($photo);
        $this->em->persist(new BibSuppression($this->event, '1423'));
        $this->em->flush();

        // Re-ingest re-dispatches extraction; the same bib must NOT reappear.
        ($this->handler)(new ExtractPhotoAttributes($photo->getId() ?? 0));
        $this->assertSame([], $this->valuesOfType($photo, PhotoAttributeType::Bib));
    }
}
