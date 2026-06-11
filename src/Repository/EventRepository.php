<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
final class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    public function findOneBySlug(string $slug): ?Event
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    public function countByOwner(User $owner): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.owner = :owner')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
