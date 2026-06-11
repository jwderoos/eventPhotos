<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invitation>
 */
final class InvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invitation::class);
    }

    public function findBySelector(string $selector): ?Invitation
    {
        return $this->findOneBy(['selector' => $selector]);
    }

    /**
     * @return list<Invitation>
     */
    public function findAllOrderedByCreated(): array
    {
        return array_values($this->findBy([], ['createdAt' => 'DESC']));
    }
}
