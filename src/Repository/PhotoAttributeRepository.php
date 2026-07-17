<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\PhotoAttribute;
use App\Entity\PhotoAttributeType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PhotoAttribute>
 */
final class PhotoAttributeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PhotoAttribute::class);
    }

    /**
     * @return list<PhotoAttribute>
     */
    public function findForPhoto(Photo $photo): array
    {
        /** @var list<PhotoAttribute> $result */
        $result = $this->findBy(['photo' => $photo]);

        return $result;
    }

    /**
     * Per-value tag counts for one event's photos, for the admin tag overview.
     * Counts distinct photos per (type, value) so a photo tagged the same value
     * twice never double-counts. Ordered by type, then most-common first.
     *
     * @return list<array{type: string, value: string, count: int}>
     */
    public function aggregateForEvent(Event $event): array
    {
        /** @var list<array{type: mixed, value: mixed, count: mixed}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.type AS type', 'a.value AS value', 'COUNT(DISTINCT IDENTITY(a.photo)) AS count')
            ->innerJoin('a.photo', 'p')
            ->andWhere('p.event = :event')
            ->setParameter('event', $event)
            ->groupBy('a.type')
            ->addGroupBy('a.value')
            ->orderBy('a.type', 'ASC')
            ->addOrderBy('count', 'DESC')
            ->addOrderBy('a.value', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $type  = $row['type'];
            $value = $row['value'];
            $count = $row['count'];

            if ($type instanceof PhotoAttributeType) {
                $typeValue = $type->value;
            } else {
                $typeValue = is_scalar($type) ? (string) $type : '';
            }

            $result[] = [
                'type'  => $typeValue,
                'value' => is_scalar($value) ? (string) $value : '',
                'count' => is_numeric($count) ? (int) $count : 0,
            ];
        }

        return $result;
    }

    public function deleteForPhoto(Photo $photo): void
    {
        $this->getEntityManager()
            ->createQuery('DELETE FROM App\Entity\PhotoAttribute a WHERE a.photo = :photo')
            ->setParameter('photo', $photo)
            ->execute();
    }

    /**
     * Remove every stored tag for a specific bib value across ALL photos in the event.
     * Used by organizer de-index (#109): existing bib tags must disappear, while the
     * inserted BibSuppression (Plan A) blocks any future re-add on re-ingest.
     */
    public function deleteBibForEvent(Event $event, string $bibValue): void
    {
        $this->getEntityManager()
            ->createQuery(
                'DELETE FROM App\Entity\PhotoAttribute a '
                . 'WHERE a.photo IN ('
                . 'SELECT p.id FROM App\Entity\Photo p WHERE p.event = :event'
                . ') AND a.type = :type AND a.value = :bib'
            )
            ->setParameter('event', $event)
            ->setParameter('type', PhotoAttributeType::Bib)
            ->setParameter('bib', $bibValue)
            ->execute();
    }
}
