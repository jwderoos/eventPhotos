<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use Throwable;
use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\PhotoStatus;
use App\Entity\User;
use App\Message\ProcessPhoto;
use App\MessageHandler\ProcessPhotoHandler;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProcessPhotoHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private FilesystemOperator $originals;

    private FilesystemOperator $thumbs;

    private FilesystemOperator $previews;

    private ProcessPhotoHandler $handler;

    private Event $event;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var FilesystemOperator $originals */
        $originals = $c->get('photo_originals_storage');
        /** @var FilesystemOperator $thumbs */
        $thumbs = $c->get('photo_thumbs_storage');
        /** @var FilesystemOperator $previews */
        $previews = $c->get('photo_previews_storage');
        /** @var ProcessPhotoHandler $handler */
        $handler = $c->get(ProcessPhotoHandler::class);

        $this->em = $em;
        $this->originals = $originals;
        $this->thumbs = $thumbs;
        $this->previews = $previews;
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
        $this->event->setTimezone('Europe/Amsterdam');

        $this->em->persist($this->event);
        $this->em->flush();
    }

    public function testHappyPathReadsExifAndWritesDerivatives(): void
    {
        $photo = $this->seedPending('with-datetime-original.jpg', 'aa');

        ($this->handler)(new ProcessPhoto($photo->getId() ?? 0));
        $this->em->refresh($photo);

        $this->assertSame(PhotoStatus::Ready, $photo->getStatus());
        $this->assertEquals(
            new DateTimeImmutable('2026-06-10 10:34:56', new DateTimeZone('UTC')),
            $photo->getTakenAt(),
        );
        $path = sprintf('event-%d/%d.jpg', $this->event->getId(), $photo->getId());
        $this->assertTrue($this->thumbs->fileExists($path));
        $this->assertTrue($this->previews->fileExists($path));
        $this->assertFalse(
            $this->originals->fileExists($path),
            'Original should be deleted after successful ingest.',
        );
        $this->assertSame(
            $this->thumbs->fileSize($path) + $this->previews->fileSize($path),
            $photo->getDerivativeBytes(),
            'Stored derivativeBytes should equal thumb + preview on-disk size.',
        );
    }

    public function testRejectsWhenExifMissing(): void
    {
        $photo = $this->seedPending('no-exif.jpg', 'bb');

        ($this->handler)(new ProcessPhoto($photo->getId() ?? 0));
        $this->em->refresh($photo);

        $this->assertSame(PhotoStatus::Failed, $photo->getStatus());
        $this->assertNotNull($photo->getProcessingError());
        $this->assertStringContainsString('DateTimeOriginal', $photo->getProcessingError());
        $path = sprintf('event-%d/%d.jpg', $this->event->getId(), $photo->getId());
        $this->assertFalse(
            $this->originals->fileExists($path),
            'Original should be deleted after PhotoRejected (non-recoverable failure).',
        );
    }

    public function testIdempotentWhenAlreadyReady(): void
    {
        $photo = $this->seedPending('with-datetime-original.jpg', 'cc');
        ($this->handler)(new ProcessPhoto($photo->getId() ?? 0));

        // Run again — handler should no-op (no exception, status unchanged)
        ($this->handler)(new ProcessPhoto($photo->getId() ?? 0));
        $this->em->refresh($photo);

        $this->assertSame(PhotoStatus::Ready, $photo->getStatus());
    }

    public function testNoopWhenPhotoDeleted(): void
    {
        $this->expectNotToPerformAssertions();

        ($this->handler)(new ProcessPhoto(999999));
    }

    private function seedPending(string $fixtureFile, string $hashSeed): Photo
    {
        $photo = new Photo(
            event: $this->event,
            contentHash: str_pad($hashSeed, 64, '0'),
            originalFilename: $fixtureFile,
            byteSize: 100,
        );
        $this->em->persist($photo);
        $this->em->flush();

        $bytes = (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/photos/' . $fixtureFile);
        $this->originals->write(sprintf('event-%d/%d.jpg', $this->event->getId(), $photo->getId()), $bytes);

        return $photo;
    }

    protected function tearDown(): void
    {
        foreach ([$this->originals, $this->thumbs, $this->previews] as $fs) {
            try {
                $fs->deleteDirectory(sprintf('event-%d', $this->event->getId()));
            } catch (Throwable) {
            }
        }

        parent::tearDown();
    }
}
