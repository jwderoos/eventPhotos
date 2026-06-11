<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Event;
use App\Entity\EventCollection;
use App\Entity\User;
use App\Repository\EventCollectionRepository;
use App\Repository\EventRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CountByOwnerTest extends KernelTestCase
{
    public function testCountsEventsAndCollectionsByOwner(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var EventRepository $events */
        $events = $container->get(EventRepository::class);
        /** @var EventCollectionRepository $collections */
        $collections = $container->get(EventCollectionRepository::class);

        $owner   = new User('owner@example.com', 'Owner');
        $someone = new User('someone@example.com', 'Someone');
        $em->persist($owner);
        $em->persist($someone);

        $em->persist(new Event('e1', 'E1', new DateTimeImmutable('2026-07-01'), $owner));
        $em->persist(new Event('e2', 'E2', new DateTimeImmutable('2026-07-02'), $owner));
        $em->persist(new Event('e3', 'E3', new DateTimeImmutable('2026-07-03'), $someone));
        $em->persist(new EventCollection('c1', 'C1', $owner));
        $em->flush();

        $this->assertSame(2, $events->countByOwner($owner));
        $this->assertSame(1, $events->countByOwner($someone));
        $this->assertSame(1, $collections->countByOwner($owner));
        $this->assertSame(0, $collections->countByOwner($someone));
    }
}
