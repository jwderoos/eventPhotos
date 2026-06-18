<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserMailConfigAudit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserMailConfigAudit>
 */
final class UserMailConfigAuditRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserMailConfigAudit::class);
    }

    /** @return list<UserMailConfigAudit> */
    public function findForUserOrderedDesc(User $user): array
    {
        /** @var list<UserMailConfigAudit> $rows */
        $rows = $this->createQueryBuilder('a')
            ->andWhere('a.user = :user')
            ->setParameter('user', $user)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
