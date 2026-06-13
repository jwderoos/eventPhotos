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

    public function findFirstReadyTakenAt(Event $event): ?DateTimeImmutable
    {
        return $this->findReadyTakenAtOrdered($event, 'ASC');
    }

    public function findLastReadyTakenAt(Event $event): ?DateTimeImmutable
    {
        return $this->findReadyTakenAtOrdered($event, 'DESC');
    }

    /** @param 'ASC'|'DESC' $direction */
    private function findReadyTakenAtOrdered(Event $event, string $direction): ?DateTimeImmutable
    {
        /** @var array{takenAt: ?DateTimeImmutable}|null $row */
        $row = $this->createQueryBuilder('p')
            ->select('p.takenAt')
            ->andWhere('p.event = :event')
            ->andWhere('p.status = :status')
            ->setParameter('event', $event)
            ->setParameter('status', PhotoStatus::Ready)
            ->orderBy('p.takenAt', $direction)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row['takenAt'] ?? null;
    }

    public function findPreviousReadyTakenAt(Event $event, DateTimeImmutable $cursor): ?DateTimeImmutable
    {
        return $this->findReadyTakenAtRelativeTo($event, $cursor, 'p.takenAt < :cursor', 'DESC');
    }

    public function findNextReadyTakenAt(Event $event, DateTimeImmutable $cursor): ?DateTimeImmutable
    {
        return $this->findReadyTakenAtRelativeTo($event, $cursor, 'p.takenAt > :cursor', 'ASC');
    }

    /** @param 'ASC'|'DESC' $direction */
    private function findReadyTakenAtRelativeTo(
        Event $event,
        DateTimeImmutable $cursor,
        string $predicate,
        string $direction,
    ): ?DateTimeImmutable {
        /** @var array{takenAt: ?DateTimeImmutable}|null $row */
        $row = $this->createQueryBuilder('p')
            ->select('p.takenAt')
            ->andWhere('p.event = :event')
            ->andWhere('p.status = :status')
            ->andWhere($predicate)
            ->setParameter('event', $event)
            ->setParameter('status', PhotoStatus::Ready)
            ->setParameter('cursor', $cursor)
            ->orderBy('p.takenAt', $direction)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row['takenAt'] ?? null;
    }

    /**
     * Returns total stored bytes per event id (derivative bytes if recorded,
     * else original upload size). Events with no photos are absent from the
     * returned map.
     *
     * @param  list<int> $eventIds
     * @return array<int, int>
     */
    public function sumBytesByEventIds(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        /** @var list<array{event_id: int|string, total_bytes: int|string|null}> $rows */
        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.event) AS event_id, SUM(COALESCE(p.derivativeBytes, p.byteSize)) AS total_bytes')
            ->andWhere('p.event IN (:eventIds)')
            ->setParameter('eventIds', $eventIds)
            ->groupBy('p.event')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['event_id']] = (int) ($row['total_bytes'] ?? 0);
        }

        return $out;
    }

    public function deleteAllForEvent(Event $event): int
    {
        $deleted = $this->createQueryBuilder('p')
            ->delete()
            ->andWhere('p.event = :event')
            ->setParameter('event', $event)
            ->getQuery()
            ->execute();

        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * @return array{photos: list<Photo>, total: int}
     */
    public function paginateForEvent(Event $event, int $page, int $perPage): array
    {
        $page    = max(1, $page);
        $perPage = max(1, $perPage);
        $offset  = ($page - 1) * $perPage;

        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.event = :event')
            ->setParameter('event', $event);

        /** @var list<Photo> $photos */
        $photos = (clone $qb)
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $total = (int) (clone $qb)
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return ['photos' => $photos, 'total' => $total];
    }
}
