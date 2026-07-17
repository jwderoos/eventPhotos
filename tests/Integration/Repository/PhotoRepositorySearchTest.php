<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Photo;
use App\Entity\PhotoAttributeType;
use App\Repository\Filter\PhotoAttributeFilter;
use App\Repository\PhotoRepository;
use App\Tests\Support\PhotoFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PhotoRepositorySearchTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private PhotoRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var PhotoRepository $repo */
        $repo = $c->get(PhotoRepository::class);

        $this->em   = $em;
        $this->repo = $repo;
    }

    public function testColourFilterNarrowsToMatchingPhotos(): void
    {
        $event  = PhotoFixtures::event($this->em);
        $orange = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:00:00');
        $blue   = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:01:00');
        PhotoFixtures::tagColour($this->em, $orange, 'orange');
        PhotoFixtures::tagColour($this->em, $blue, 'blue');
        $this->em->flush();

        $result = $this->repo->searchReady($event, new PhotoAttributeFilter(colours: ['orange']), 200);

        $this->assertSame([$orange->getId()], array_map(static fn (Photo $p): ?int => $p->getId(), $result));
    }

    public function testColourAndGarmentAreAnded(): void
    {
        $event = PhotoFixtures::event($this->em);
        $match = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:00:00');
        $half  = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:01:00');
        PhotoFixtures::tagColour($this->em, $match, 'orange');
        PhotoFixtures::tag($this->em, $match, PhotoAttributeType::ClothingType, 't-shirt');
        PhotoFixtures::tagColour($this->em, $half, 'orange');
        $this->em->flush();

        $result = $this->repo->searchReady(
            $event,
            new PhotoAttributeFilter(colours: ['orange'], garments: ['t-shirt']),
            200,
        );

        $this->assertSame([$match->getId()], array_map(static fn (Photo $p): ?int => $p->getId(), $result));
    }

    public function testMultipleColoursMatchingSamePhotoDoesNotDuplicate(): void
    {
        $event = PhotoFixtures::event($this->em);
        $photo = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:00:00');
        PhotoFixtures::tagColour($this->em, $photo, 'orange');
        PhotoFixtures::tagColour($this->em, $photo, 'blue');
        $this->em->flush();

        $result = $this->repo->searchReady(
            $event,
            new PhotoAttributeFilter(colours: ['orange', 'blue']),
            200,
        );

        $this->assertCount(1, $result);
    }

    public function testBibExactMatch(): void
    {
        $event = PhotoFixtures::event($this->em);
        $photo = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:00:00');
        PhotoFixtures::tagBib($this->em, $photo, '1423');
        $this->em->flush();

        $this->assertCount(1, $this->repo->searchReady($event, new PhotoAttributeFilter(bib: '1423'), 200));
        $this->assertCount(0, $this->repo->searchReady($event, new PhotoAttributeFilter(bib: '9999'), 200));
    }
}
