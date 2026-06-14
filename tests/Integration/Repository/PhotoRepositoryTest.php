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

        $this->event = new Event(
            'demo',
            'Demo',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
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
        $photo->markReady(new DateTimeImmutable($takenAt, new DateTimeZone('UTC')), 100, 100, 1024);

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

    public function testFindFirstReadyTakenAtReturnsNullWhenNoReadyPhotos(): void
    {
        $this->createPending();
        $this->em->flush();

        $this->assertNotInstanceOf(DateTimeImmutable::class, $this->repo->findFirstReadyTakenAt($this->event));
    }

    public function testFindLastReadyTakenAtReturnsNullWhenNoReadyPhotos(): void
    {
        $this->createPending();
        $this->em->flush();

        $this->assertNotInstanceOf(DateTimeImmutable::class, $this->repo->findLastReadyTakenAt($this->event));
    }

    public function testFindFirstReadyTakenAtReturnsEarliestReady(): void
    {
        $this->createReady('2026-06-10 12:30:00');
        $this->createReady('2026-06-10 11:00:00');
        $this->createReady('2026-06-10 13:45:00');
        $this->em->flush();

        $first = $this->repo->findFirstReadyTakenAt($this->event);

        $this->assertInstanceOf(DateTimeImmutable::class, $first);
        $this->assertSame('2026-06-10 11:00:00', $first->format('Y-m-d H:i:s'));
    }

    public function testFindLastReadyTakenAtReturnsLatestReady(): void
    {
        $this->createReady('2026-06-10 12:30:00');
        $this->createReady('2026-06-10 11:00:00');
        $this->createReady('2026-06-10 13:45:00');
        $this->em->flush();

        $last = $this->repo->findLastReadyTakenAt($this->event);

        $this->assertInstanceOf(DateTimeImmutable::class, $last);
        $this->assertSame('2026-06-10 13:45:00', $last->format('Y-m-d H:i:s'));
    }

    public function testFindFirstLastIgnorePendingAndFailedPhotos(): void
    {
        $this->createPending();                          // takenAt is null
        $ready = $this->createReady('2026-06-10 12:00:00');
        // Photo::markFailed refuses transition from Ready; failed photos
        // can only originate from Pending, so we mark a pending photo failed.
        $failed = $this->createPending();
        $failed->markFailed('forced');

        $this->em->flush();

        $first = $this->repo->findFirstReadyTakenAt($this->event);
        $last  = $this->repo->findLastReadyTakenAt($this->event);

        $readyTakenAt = $ready->getTakenAt();
        $this->assertInstanceOf(DateTimeImmutable::class, $first);
        $this->assertInstanceOf(DateTimeImmutable::class, $last);
        $this->assertInstanceOf(DateTimeImmutable::class, $readyTakenAt);
        $this->assertSame($readyTakenAt->format('Y-m-d H:i:s'), $first->format('Y-m-d H:i:s'));
        $this->assertSame($readyTakenAt->format('Y-m-d H:i:s'), $last->format('Y-m-d H:i:s'));
    }

    public function testFindPreviousReadyTakenAtReturnsNullWhenCursorAtOrBeforeEarliest(): void
    {
        $this->createReady('2026-06-10 12:00:00');
        $this->createReady('2026-06-10 13:00:00');
        $this->em->flush();

        $tz = new DateTimeZone('UTC');

        $this->assertNotInstanceOf(DateTimeImmutable::class, $this->repo->findPreviousReadyTakenAt(
            $this->event,
            new DateTimeImmutable('2026-06-10 12:00:00', $tz),
        ));
        $this->assertNotInstanceOf(DateTimeImmutable::class, $this->repo->findPreviousReadyTakenAt(
            $this->event,
            new DateTimeImmutable('2026-06-10 11:00:00', $tz),
        ));
    }

    public function testFindPreviousReadyTakenAtReturnsStrictlyEarlierPhoto(): void
    {
        $this->createReady('2026-06-10 11:00:00');
        $this->createReady('2026-06-10 12:00:00');
        $this->createReady('2026-06-10 13:00:00');
        $this->em->flush();

        $previous = $this->repo->findPreviousReadyTakenAt(
            $this->event,
            new DateTimeImmutable('2026-06-10 12:30:00', new DateTimeZone('UTC')),
        );

        $this->assertInstanceOf(DateTimeImmutable::class, $previous);
        $this->assertSame('2026-06-10 12:00:00', $previous->format('Y-m-d H:i:s'));
    }

    public function testFindNextReadyTakenAtReturnsNullWhenCursorAtOrAfterLatest(): void
    {
        $this->createReady('2026-06-10 12:00:00');
        $this->createReady('2026-06-10 13:00:00');
        $this->em->flush();

        $tz = new DateTimeZone('UTC');

        $this->assertNotInstanceOf(DateTimeImmutable::class, $this->repo->findNextReadyTakenAt(
            $this->event,
            new DateTimeImmutable('2026-06-10 13:00:00', $tz),
        ));
        $this->assertNotInstanceOf(DateTimeImmutable::class, $this->repo->findNextReadyTakenAt(
            $this->event,
            new DateTimeImmutable('2026-06-10 14:30:00', $tz),
        ));
    }

    public function testFindNextReadyTakenAtReturnsStrictlyLaterPhoto(): void
    {
        $this->createReady('2026-06-10 11:00:00');
        $this->createReady('2026-06-10 12:00:00');
        $this->createReady('2026-06-10 13:00:00');
        $this->em->flush();

        $next = $this->repo->findNextReadyTakenAt(
            $this->event,
            new DateTimeImmutable('2026-06-10 11:30:00', new DateTimeZone('UTC')),
        );

        $this->assertInstanceOf(DateTimeImmutable::class, $next);
        $this->assertSame('2026-06-10 12:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function testFindReadyNeighborNextReturnsImmediateLaterPhoto(): void
    {
        $earlier = $this->createReady('2026-06-10 11:00:00');
        $middle  = $this->createReady('2026-06-10 12:00:00');
        $later   = $this->createReady('2026-06-10 13:00:00');
        $this->em->flush();

        $this->assertSame($middle->getId(), $this->repo->findReadyNeighbor($earlier, 'next')?->getId());
        $this->assertSame($later->getId(), $this->repo->findReadyNeighbor($middle, 'next')?->getId());
        $this->assertNotInstanceOf(Photo::class, $this->repo->findReadyNeighbor($later, 'next'));
    }

    public function testFindReadyNeighborPrevReturnsImmediateEarlierPhoto(): void
    {
        $earlier = $this->createReady('2026-06-10 11:00:00');
        $middle  = $this->createReady('2026-06-10 12:00:00');
        $later   = $this->createReady('2026-06-10 13:00:00');
        $this->em->flush();

        $this->assertSame($middle->getId(), $this->repo->findReadyNeighbor($later, 'prev')?->getId());
        $this->assertSame($earlier->getId(), $this->repo->findReadyNeighbor($middle, 'prev')?->getId());
        $this->assertNotInstanceOf(Photo::class, $this->repo->findReadyNeighbor($earlier, 'prev'));
    }

    public function testFindReadyNeighborBreaksTiesOnId(): void
    {
        $first  = $this->createReady('2026-06-10 12:00:00');
        $second = $this->createReady('2026-06-10 12:00:00');
        $third  = $this->createReady('2026-06-10 12:00:00');
        $this->em->flush();

        $this->assertSame($second->getId(), $this->repo->findReadyNeighbor($first, 'next')?->getId());
        $this->assertSame($third->getId(), $this->repo->findReadyNeighbor($second, 'next')?->getId());
        $this->assertSame($second->getId(), $this->repo->findReadyNeighbor($third, 'prev')?->getId());
        $this->assertSame($first->getId(), $this->repo->findReadyNeighbor($second, 'prev')?->getId());
    }

    public function testFindReadyNeighborSkipsPendingAndFailed(): void
    {
        $earlier = $this->createReady('2026-06-10 11:00:00');
        $this->createPending();
        $failed = $this->createPending();
        $failed->markFailed('forced');

        $later   = $this->createReady('2026-06-10 13:00:00');
        $this->em->flush();

        $this->assertSame($later->getId(), $this->repo->findReadyNeighbor($earlier, 'next')?->getId());
        $this->assertSame($earlier->getId(), $this->repo->findReadyNeighbor($later, 'prev')?->getId());
    }

    public function testCountReadyExcludesNonReady(): void
    {
        $this->createReady('2026-06-10 12:00:00');
        $this->createReady('2026-06-10 12:05:00');
        $this->createPending();
        $this->em->flush();

        $this->assertSame(2, $this->repo->countReady($this->event));
    }

    public function testCountReadyBeforeReturnsRankMinusOneAndBreaksTiesOnId(): void
    {
        $first  = $this->createReady('2026-06-10 11:00:00');
        $tieA   = $this->createReady('2026-06-10 12:00:00');
        $tieB   = $this->createReady('2026-06-10 12:00:00');
        $last   = $this->createReady('2026-06-10 13:00:00');
        $this->createPending();
        $this->em->flush();

        $this->assertSame(0, $this->repo->countReadyBefore($first));
        $this->assertSame(1, $this->repo->countReadyBefore($tieA));
        $this->assertSame(2, $this->repo->countReadyBefore($tieB));
        $this->assertSame(3, $this->repo->countReadyBefore($last));
    }

    public function testFindPreviousNextSkipPending(): void
    {
        // Note: Photo::markFailed forbids Ready→Failed. Only Pending→Failed is legal,
        // so a "Failed photo with a real takenAt" is not constructible. We exercise
        // the status filter via a Pending photo (no takenAt) and confirm Previous/Next
        // jump straight from earliest Ready to latest Ready across the gap.
        $this->createReady('2026-06-10 11:00:00');
        $this->createPending();
        $this->createReady('2026-06-10 13:00:00');
        $this->em->flush();

        $tz = new DateTimeZone('UTC');

        $next = $this->repo->findNextReadyTakenAt(
            $this->event,
            new DateTimeImmutable('2026-06-10 11:30:00', $tz),
        );
        $previous = $this->repo->findPreviousReadyTakenAt(
            $this->event,
            new DateTimeImmutable('2026-06-10 12:30:00', $tz),
        );

        $this->assertInstanceOf(DateTimeImmutable::class, $next);
        $this->assertInstanceOf(DateTimeImmutable::class, $previous);
        $this->assertSame('2026-06-10 13:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-10 11:00:00', $previous->format('Y-m-d H:i:s'));
    }
}
