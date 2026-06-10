<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\PhotoStatus;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Photo>
 */
final class PhotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Photo::class);
    }

    /**
     * @return list<Photo>
     */
    public function findReadyInWindow(
        Event $event,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        int $limit = 200,
    ): array {
        /** @var list<Photo> $result */
        $result = $this->createQueryBuilder('p')
            ->andWhere('p.event = :event')
            ->andWhere('p.status = :status')
            ->andWhere('p.takenAt BETWEEN :start AND :end')
            ->setParameter('event', $event)
            ->setParameter('status', PhotoStatus::Ready)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('p.takenAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }
}
