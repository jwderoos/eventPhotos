<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserSession>
 */
final class UserSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserSession::class);
    }

    public function findOneBySessId(string $sessId): ?UserSession
    {
        return $this->findOneBy(['sessId' => $sessId]);
    }

    /**
     * @return list<UserSession>
     */
    public function findForUserOrderedByActivity(User $user): array
    {
        /** @var list<UserSession> $results */
        $results = $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.lastSeenAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $results;
    }
}
