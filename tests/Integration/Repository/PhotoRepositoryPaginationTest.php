<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\User;
use App\Repository\PhotoRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PhotoRepositoryPaginationTest extends KernelTestCase
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

        $this->em   = $em;
        $this->repo = $repo;

        $owner = new User('owner@example.test', 'Owner');
        $owner->setPassword('x');

        $this->em->persist($owner);

        $this->event = new Event('demo', 'Demo', new DateTimeImmutable('2026-06-10'), $owner);
        $this->event->setTimezone('UTC');

        $this->em->persist($this->event);
        $this->em->flush();
    }

    public function testReturnsRequestedPageAndTotal(): void
    {
        for ($i = 0; $i < 150; ++$i) {
            $this->createPending('file-' . $i . '.jpg');
        }

        $this->em->flush();

        $page1 = $this->repo->paginateForEvent($this->event, 1, 100);
        $page2 = $this->repo->paginateForEvent($this->event, 2, 100);
        $page3 = $this->repo->paginateForEvent($this->event, 3, 100);

        $this->assertSame(150, $page1['total']);
        $this->assertCount(100, $page1['photos']);
        $this->assertCount(50, $page2['photos']);
        $this->assertCount(0, $page3['photos']);
    }

    public function testOrdersByCreatedAtDescAcrossPages(): void
    {
        $photos = [];
        for ($i = 0; $i < 5; ++$i) {
            $photos[] = $this->createPending('f-' . $i . '.jpg');
        }

        $this->em->flush();

        $page1 = $this->repo->paginateForEvent($this->event, 1, 3);
        $page2 = $this->repo->paginateForEvent($this->event, 2, 3);

        // Newest first: the last-created photo should be index 0 on page 1.
        $this->assertSame($photos[4]->getId(), $page1['photos'][0]->getId());
        $this->assertSame($photos[2]->getId(), $page1['photos'][2]->getId());
        $this->assertSame($photos[1]->getId(), $page2['photos'][0]->getId());
        $this->assertSame($photos[0]->getId(), $page2['photos'][1]->getId());
    }

    public function testScopesToTheGivenEventOnly(): void
    {
        $other = new Event('other', 'Other', new DateTimeImmutable('2026-06-10'), $this->event->getOwner());
        $other->setTimezone('UTC');

        $this->em->persist($other);

        $this->createPending('mine.jpg');

        $strangerPhoto = new Photo($other, bin2hex(random_bytes(32)), 'stranger.jpg', 100);
        $this->em->persist($strangerPhoto);
        $this->em->flush();

        $result = $this->repo->paginateForEvent($this->event, 1, 100);

        $this->assertSame(1, $result['total']);
        $this->assertSame('mine.jpg', $result['photos'][0]->getOriginalFilename());
    }

    private function createPending(string $filename): Photo
    {
        $photo = new Photo(
            event: $this->event,
            contentHash: bin2hex(random_bytes(32)),
            originalFilename: $filename,
            byteSize: 100,
        );
        $this->em->persist($photo);

        return $photo;
    }
}
