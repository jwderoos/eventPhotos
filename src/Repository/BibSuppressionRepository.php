<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BibSuppression;
use App\Entity\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BibSuppression>
 */
final class BibSuppressionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BibSuppression::class);
    }

    public function isSuppressed(Event $event, string $bibNumber): bool
    {
        return $this->count(['event' => $event, 'bibNumber' => $bibNumber]) > 0;
    }

    /**
     * @return list<string>
     */
    public function suppressedBibNumbers(Event $event): array
    {
        /** @var list<array{bibNumber: string}> $rows */
        $rows = $this->createQueryBuilder('b')
            ->select('b.bibNumber')
            ->where('b.event = :event')
            ->setParameter('event', $event)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r): string => $r['bibNumber'], $rows);
    }
}
