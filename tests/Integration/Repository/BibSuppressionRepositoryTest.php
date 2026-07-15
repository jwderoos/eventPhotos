<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\BibSuppression;
use App\Entity\Event;
use App\Entity\User;
use App\Repository\BibSuppressionRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BibSuppressionRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private BibSuppressionRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var BibSuppressionRepository $repo */
        $repo = $container->get(BibSuppressionRepository::class);
        $this->em   = $em;
        $this->repo = $repo;
    }

    private function persistEvent(string $slug): Event
    {
        $user = new User('org-' . $slug . '@example.test', 'Org');
        $this->em->persist($user);

        $event = new Event(
            $slug,
            'Event ' . $slug,
            new DateTimeImmutable('2026-05-01 09:00'),
            new DateTimeImmutable('2026-05-01 12:00'),
            $user,
        );
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    public function testIsSuppressedAndListing(): void
    {
        $event = $this->persistEvent('run-a');

        $this->assertFalse($this->repo->isSuppressed($event, '1423'));

        $this->em->persist(new BibSuppression($event, '1423'));
        $this->em->flush();

        $this->assertTrue($this->repo->isSuppressed($event, '1423'));
        $this->assertSame(['1423'], $this->repo->suppressedBibNumbers($event));
    }

    public function testUniqueConstraintPerEventAndBib(): void
    {
        $event = $this->persistEvent('run-b');
        $this->em->persist(new BibSuppression($event, '77'));
        $this->em->flush();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->persist(new BibSuppression($event, '77'));
        $this->em->flush();
    }
}
