<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\PhotoAttributeType;
use App\Entity\PhotoStatus;
use App\Repository\Filter\PhotoAttributeFilter;
use DateTimeImmutable;
use DateTimeZone;
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
            ->setParameter('start', $this->toUtc($start))
            ->setParameter('end', $this->toUtc($end))
            ->orderBy('p.takenAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Filtered gallery search over allowlisted tags. Semantics:
     *   results = P_bib ∪ (P_colour ∩ P_garment ∩ P_scene)
     * Each present attribute dimension is an EXISTS-IN subquery (values within a
     * dimension OR-ed, dimensions AND-ed). The bib term is EXISTS on the bib tag
     * minus any BibSuppression, OR-ed against the attribute group so a matched
     * bib surfaces a photo even when its clothing doesn't match, and vice versa.
     * EXISTS (not joins) keeps rows unique without `distinct`. Spans the whole
     * event timeline — this is a "find me" query, not a browse.
     *
     * @return list<Photo>
     */
    public function searchReady(Event $event, PhotoAttributeFilter $filter, int $limit): array
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.event = :event')
            ->andWhere('p.status = :status')
            ->setParameter('event', $event)
            ->setParameter('status', PhotoStatus::Ready)
            ->orderBy('p.takenAt', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->setMaxResults($limit);

        $orX = $qb->expr()->orX();

        $andParts = [];
        if ($filter->colours !== []) {
            $andParts[] = $this->attributeExists('pac', 'colourType', 'colours');
            $qb->setParameter('colourType', PhotoAttributeType::ClothingColor)
                ->setParameter('colours', $filter->colours);
        }

        if ($filter->garments !== []) {
            $andParts[] = $this->attributeExists('pag', 'garmentType', 'garments');
            $qb->setParameter('garmentType', PhotoAttributeType::ClothingType)
                ->setParameter('garments', $filter->garments);
        }

        if ($filter->scenes !== []) {
            $andParts[] = $this->attributeExists('pas', 'sceneType', 'scenes');
            $qb->setParameter('sceneType', PhotoAttributeType::Scene)
                ->setParameter('scenes', $filter->scenes);
        }

        if ($andParts !== []) {
            $orX->add(implode(' AND ', $andParts));
        }

        if ($filter->bib !== null) {
            $orX->add(
                'EXISTS ('
                . 'SELECT 1 FROM App\Entity\PhotoAttribute pab '
                . 'WHERE pab.photo = p AND pab.type = :bibType AND pab.value = :bib'
                . ') AND NOT EXISTS ('
                . 'SELECT 1 FROM App\Entity\BibSuppression bs '
                . 'WHERE bs.event = :event AND bs.bibNumber = :bib'
                . ')'
            );
            $qb->setParameter('bibType', PhotoAttributeType::Bib)
                ->setParameter('bib', $filter->bib);
        }

        if ($orX->getParts() === []) {
            return [];
        }

        $qb->andWhere($orX);

        /** @var list<Photo> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    private function attributeExists(string $alias, string $typeParam, string $valuesParam): string
    {
        return sprintf(
            'EXISTS (SELECT 1 FROM App\Entity\PhotoAttribute %1$s '
            . 'WHERE %1$s.photo = p AND %1$s.type = :%2$s AND %1$s.value IN (:%3$s))',
            $alias,
            $typeParam,
            $valuesParam,
        );
    }

    /**
     * Latest `updatedAt` across Ready photos for the event, or null when none
     * are Ready. Drives the gallery HTML ETag (§3.3) — any transition into
     * Ready or post-Ready mutation bumps `updatedAt` via the PreUpdate hook,
     * so the ETag invalidates exactly when the gallery's output could change.
     */
    public function lastReadyUpdatedAtForEvent(Event $event): ?DateTimeImmutable
    {
        /** @var array{updatedAt: ?DateTimeImmutable}|null $row */
        $row = $this->createQueryBuilder('p')
            ->select('p.updatedAt')
            ->andWhere('p.event = :event')
            ->andWhere('p.status = :status')
            ->setParameter('event', $event)
            ->setParameter('status', PhotoStatus::Ready)
            ->orderBy('p.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row['updatedAt'] ?? null;
    }

    public function countReady(Event $event): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.event = :event')
            ->andWhere('p.status = :status')
            ->setParameter('event', $event)
            ->setParameter('status', PhotoStatus::Ready)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countTagged(Event $event): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.event = :event')
            ->andWhere('p.status = :status')
            ->andWhere('p.attributesExtractedAt IS NOT NULL')
            ->setParameter('event', $event)
            ->setParameter('status', PhotoStatus::Ready)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Count of Ready photos in the event ordered strictly before `$photo` in
     * the canonical `(takenAt ASC, id ASC)` timeline. Caller derives the
     * photo's 1-based rank by adding 1.
     */
    public function countReadyBefore(Photo $photo): int
    {
        $takenAt = $photo->getTakenAt();
        if (!$takenAt instanceof DateTimeImmutable) {
            return 0;
        }

        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.event = :event')
            ->andWhere('p.status = :status')
            ->andWhere('(p.takenAt < :takenAt OR (p.takenAt = :takenAt AND p.id < :id))')
            ->setParameter('event', $photo->getEvent())
            ->setParameter('status', PhotoStatus::Ready)
            ->setParameter('takenAt', $this->toUtc($takenAt))
            ->setParameter('id', $photo->getId())
            ->getQuery()
            ->getSingleScalarResult();
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
            ->setParameter('cursor', $this->toUtc($cursor))
            ->orderBy('p.takenAt', $direction)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row['takenAt'] ?? null;
    }

    /**
     * Lightbox cross-window navigation (#67). Returns the Ready photo
     * adjacent to `$photo` in the event's takenAt timeline, with a stable
     * tiebreaker on `id` so two photos at the same instant always order
     * deterministically. Caller must ensure `$photo` is itself Ready.
     *
     * @param 'next'|'prev' $direction
     */
    public function findReadyNeighbor(Photo $photo, string $direction): ?Photo
    {
        $takenAt = $photo->getTakenAt();
        if (!$takenAt instanceof DateTimeImmutable) {
            return null;
        }

        $comparison = $direction === 'next' ? '>' : '<';
        $sort       = $direction === 'next' ? 'ASC' : 'DESC';

        /** @var Photo|null $result */
        $result = $this->createQueryBuilder('p')
            ->andWhere('p.event = :event')
            ->andWhere('p.status = :status')
            ->andWhere(sprintf(
                '(p.takenAt %1$s :takenAt OR (p.takenAt = :takenAt AND p.id %1$s :id))',
                $comparison,
            ))
            ->setParameter('event', $photo->getEvent())
            ->setParameter('status', PhotoStatus::Ready)
            ->setParameter('takenAt', $this->toUtc($takenAt))
            ->setParameter('id', $photo->getId())
            ->orderBy('p.takenAt', $sort)
            ->addOrderBy('p.id', $sort)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
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

    public function countForEvent(Event $event): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.event = :event')
            ->setParameter('event', $event)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Photos stranded in Pending — used by app:photo:reprocess-pending to
     * re-dispatch a fresh ingest. Bounded by $updatedBefore so an operator can
     * exclude uploads still legitimately in flight (whose message still exists).
     *
     * @return list<Photo>
     */
    public function findStalePending(DateTimeImmutable $updatedBefore, ?Event $event = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.status = :pending')
            ->andWhere('p.updatedAt <= :cutoff')
            ->setParameter('pending', PhotoStatus::Pending)
            ->setParameter('cutoff', $updatedBefore)
            ->orderBy('p.id', 'ASC');

        if ($event instanceof Event) {
            $qb->andWhere('p.event = :event')->setParameter('event', $event);
        }

        /** @var list<Photo> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    public function countByStatus(Event $event, PhotoStatus $status): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.event = :event')
            ->andWhere('p.status = :status')
            ->setParameter('event', $event)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
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

    /**
     * `Photo::$takenAt` is stored as a tz-less `timestamp` whose wall-clock is
     * UTC (ExifReader normalises to UTC before persist). Doctrine binds the
     * wall-clock of whatever tz the bound `DateTimeImmutable` carries, so a
     * cursor in the event tz would shift the comparison by the event offset.
     * Re-anchor to UTC at the boundary.
     */
    private function toUtc(DateTimeImmutable $value): DateTimeImmutable
    {
        return $value->setTimezone(new DateTimeZone('UTC'));
    }
}
