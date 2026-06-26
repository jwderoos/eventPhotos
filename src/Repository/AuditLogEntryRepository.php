<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use App\Entity\AuditLogEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLogEntry>
 */
class AuditLogEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLogEntry::class);
    }

    /**
     * @param array{
     *     actorId?: int,
     *     action?: string,
     *     targetType?: string,
     *     targetId?: int,
     *     from?: DateTimeImmutable,
     *     to?: DateTimeImmutable,
     * } $filters
     * @return array{items: list<AuditLogEntry>, total: int}
     */
    public function findFiltered(array $filters, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('a');

        if (isset($filters['actorId'])) {
            $qb->andWhere('a.actorId = :actorId')->setParameter('actorId', $filters['actorId']);
        }

        if (isset($filters['action'])) {
            $qb->andWhere('a.action = :action')->setParameter('action', $filters['action']);
        }

        if (isset($filters['targetType'])) {
            $qb->andWhere('a.targetType = :targetType')->setParameter('targetType', $filters['targetType']);
        }

        if (isset($filters['targetId'])) {
            $qb->andWhere('a.targetId = :targetId')->setParameter('targetId', $filters['targetId']);
        }

        if (isset($filters['from'])) {
            $qb->andWhere('a.createdAt >= :from')->setParameter('from', $filters['from']);
        }

        if (isset($filters['to'])) {
            $qb->andWhere('a.createdAt <= :to')->setParameter('to', $filters['to']);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();

        /** @var list<AuditLogEntry> $items */
        $items = $qb
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }
}
