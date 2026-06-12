<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserIdentity;
use App\Enum\AuthProvider;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserIdentity>
 */
class UserIdentityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserIdentity::class);
    }

    public function findBySubject(AuthProvider $provider, string $subject): ?UserIdentity
    {
        return $this->findOneBy(['provider' => $provider, 'subject' => $subject]);
    }
}
