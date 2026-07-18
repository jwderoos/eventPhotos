<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\BibSuppression;
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

    public function testSuppressedBibIsExcludedFromSearch(): void
    {
        $event = PhotoFixtures::event($this->em);
        $photo = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:00:00');
        PhotoFixtures::tagBib($this->em, $photo, '1423');
        $this->em->persist(new BibSuppression($event, '1423'));
        $this->em->flush();

        // Bib row still exists, but suppression hides it from search.
        $this->assertCount(0, $this->repo->searchReady($event, new PhotoAttributeFilter(bib: '1423'), 200));
    }

    public function testNonSuppressedBibStillMatches(): void
    {
        $event = PhotoFixtures::event($this->em);
        $photo = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:00:00');
        PhotoFixtures::tagBib($this->em, $photo, '1423');
        $this->em->persist(new BibSuppression($event, '9999')); // a different bib suppressed
        $this->em->flush();

        $this->assertCount(1, $this->repo->searchReady($event, new PhotoAttributeFilter(bib: '1423'), 200));
    }

    public function testReindexRestoresSearchVisibility(): void
    {
        $event = PhotoFixtures::event($this->em);
        $photo = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:00:00');
        PhotoFixtures::tagBib($this->em, $photo, '1423');
        $this->em->persist(new BibSuppression($event, '1423'));
        $this->em->flush();

        // Suppressed → hidden from public search.
        $this->assertCount(0, $this->repo->searchReady($event, new PhotoAttributeFilter(bib: '1423'), 200));

        // Undo: remove the suppression row (the reindexBib controller action does this).
        $suppression = $this->em->getRepository(BibSuppression::class)->findOneBy([
            'event'     => $event,
            'bibNumber' => '1423',
        ]);
        $this->assertInstanceOf(BibSuppression::class, $suppression);
        $this->em->remove($suppression);
        $this->em->flush();

        // Lossless undo: the bib is searchable again.
        $this->assertCount(1, $this->repo->searchReady($event, new PhotoAttributeFilter(bib: '1423'), 200));
    }

    public function testBibIsUnionedWithAttributes(): void
    {
        $event = PhotoFixtures::event($this->em, bibIndexing: true);

        // Matches by bib only (wrong colour).
        $bibOnly = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:00:00');
        PhotoFixtures::tagBib($this->em, $bibOnly, '1423');
        PhotoFixtures::tagColour($this->em, $bibOnly, 'black');

        // Matches by attributes only (wrong bib).
        $attrOnly = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:01:00');
        PhotoFixtures::tagBib($this->em, $attrOnly, '2000');
        PhotoFixtures::tagColour($this->em, $attrOnly, 'red');

        // Matches neither.
        $neither = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:02:00');
        PhotoFixtures::tagColour($this->em, $neither, 'blue');
        $this->em->flush();

        $result = $this->repo->searchReady(
            $event,
            new PhotoAttributeFilter(colours: ['red'], bib: '1423'),
            200,
        );

        $ids = array_map(static fn (Photo $p): ?int => $p->getId(), $result);
        $this->assertContains($bibOnly->getId(), $ids);
        $this->assertContains($attrOnly->getId(), $ids);
        $this->assertNotContains($neither->getId(), $ids);
    }

    public function testSceneFilterNarrowsToMatchingPhotos(): void
    {
        $event  = PhotoFixtures::event($this->em);
        $finish = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:00:00');
        $start  = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:01:00');
        PhotoFixtures::tag($this->em, $finish, PhotoAttributeType::Scene, 'finish-line');
        PhotoFixtures::tag($this->em, $start, PhotoAttributeType::Scene, 'start');
        $this->em->flush();

        $result = $this->repo->searchReady($event, new PhotoAttributeFilter(scenes: ['finish-line']), 200);

        $this->assertSame([$finish->getId()], array_map(static fn (Photo $p): ?int => $p->getId(), $result));
    }

    public function testColourAndSceneAreAnded(): void
    {
        $event = PhotoFixtures::event($this->em);
        $match = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:00:00');
        $half  = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:01:00');
        PhotoFixtures::tagColour($this->em, $match, 'red');
        PhotoFixtures::tag($this->em, $match, PhotoAttributeType::Scene, 'finish-line');
        PhotoFixtures::tagColour($this->em, $half, 'red');
        $this->em->flush();

        $result = $this->repo->searchReady(
            $event,
            new PhotoAttributeFilter(colours: ['red'], scenes: ['finish-line']),
            200,
        );

        $this->assertSame([$match->getId()], array_map(static fn (Photo $p): ?int => $p->getId(), $result));
    }

    public function testEmptyFilterReturnsNothing(): void
    {
        $event = PhotoFixtures::event($this->em);
        PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:00:00');
        $this->em->flush();

        $this->assertSame([], $this->repo->searchReady($event, new PhotoAttributeFilter(), 200));
    }
}
