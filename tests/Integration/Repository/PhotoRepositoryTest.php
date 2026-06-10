<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\User;
use App\Repository\PhotoRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PhotoRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private PhotoRepository $repo;

    private Event $event;


    protected function setUp(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var PhotoRepository $repo */
        $repo = self::getContainer()->get(PhotoRepository::class);

        $this->em = $em;
        $this->repo = $repo;

        $owner = new User('owner@example.test', 'Owner');
        $owner->setPassword('x');

        $this->em->persist($owner);

        $this->event = new Event('demo', 'Demo', new DateTimeImmutable('2026-06-10'), $owner);
        $this->event->setTimezone('UTC');

        $this->em->persist($this->event);
        $this->em->flush();
    }

    public function testFindsReadyPhotosInsideWindow(): void
    {
        $inside  = $this->createReady('2026-06-10 12:00:00');
        $alsoIn  = $this->createReady('2026-06-10 12:15:00');
        $this->createReady('2026-06-10 11:00:00');
        $this->createReady('2026-06-10 13:30:00');
        $this->createPending();
        $this->em->flush();

        $start = new DateTimeImmutable('2026-06-10 11:30:00', new DateTimeZone('UTC'));
        $end   = new DateTimeImmutable('2026-06-10 12:30:00', new DateTimeZone('UTC'));

        $result = $this->repo->findReadyInWindow($this->event, $start, $end);

        $this->assertCount(2, $result);
        $this->assertSame($inside->getId(), $result[0]->getId());
        $this->assertSame($alsoIn->getId(), $result[1]->getId());
    }

    public function testEndpointsAreInclusive(): void
    {
        $this->createReady('2026-06-10 11:30:00');
        $this->createReady('2026-06-10 12:30:00');
        $this->em->flush();

        $start = new DateTimeImmutable('2026-06-10 11:30:00', new DateTimeZone('UTC'));
        $end   = new DateTimeImmutable('2026-06-10 12:30:00', new DateTimeZone('UTC'));

        $result = $this->repo->findReadyInWindow($this->event, $start, $end);

        $this->assertCount(2, $result);
    }

    public function testHardCapDefaultIs200(): void
    {
        for ($i = 0; $i < 205; ++$i) {
            $this->createReady(sprintf('2026-06-10 12:%02d:%02d', intdiv($i, 60), $i % 60));
        }

        $this->em->flush();

        $start = new DateTimeImmutable('2026-06-10 11:00:00', new DateTimeZone('UTC'));
        $end   = new DateTimeImmutable('2026-06-10 13:00:00', new DateTimeZone('UTC'));

        $result = $this->repo->findReadyInWindow($this->event, $start, $end);

        $this->assertCount(200, $result);
    }

    private function createReady(string $takenAt): Photo
    {
        $photo = new Photo(
            event: $this->event,
            contentHash: bin2hex(random_bytes(32)),
            originalFilename: 'x.jpg',
            byteSize: 100,
        );
        $photo->markReady(new DateTimeImmutable($takenAt, new DateTimeZone('UTC')), 100, 100);

        $this->em->persist($photo);
        return $photo;
    }

    private function createPending(): Photo
    {
        $photo = new Photo(
            event: $this->event,
            contentHash: bin2hex(random_bytes(32)),
            originalFilename: 'x.jpg',
            byteSize: 100,
        );
        // intentionally not calling markReady — leave pending
        $this->em->persist($photo);
        return $photo;
    }
}
