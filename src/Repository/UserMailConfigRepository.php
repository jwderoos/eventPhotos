<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserMailConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserMailConfig>
 */
final class UserMailConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserMailConfig::class);
    }

    public function findOneByUser(User $user): ?UserMailConfig
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function findOneByVerificationToken(string $token): ?UserMailConfig
    {
        if ($token === '') {
            return null;
        }

        return $this->findOneBy(['verificationToken' => $token]);
    }
}
