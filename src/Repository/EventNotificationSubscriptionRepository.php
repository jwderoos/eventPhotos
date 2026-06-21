<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use App\Entity\EventNotificationStatus;
use App\Entity\EventNotificationSubscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventNotificationSubscription>
 */
final class EventNotificationSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventNotificationSubscription::class);
    }

    public function findOneByEventAndEmail(Event $event, string $email): ?EventNotificationSubscription
    {
        return $this->findOneBy(['event' => $event, 'email' => strtolower($email)]);
    }

    public function findByConfirmationToken(string $token): ?EventNotificationSubscription
    {
        return $this->findOneBy(['confirmationToken' => $token]);
    }

    public function findByUnsubscribeToken(string $token): ?EventNotificationSubscription
    {
        return $this->findOneBy(['unsubscribeToken' => $token]);
    }

    /**
     * @return array<int, EventNotificationSubscription>
     */
    public function findConfirmedByEvent(Event $event): array
    {
        /** @var array<int, EventNotificationSubscription> $result */
        $result = $this->createQueryBuilder('s')
            ->andWhere('s.event = :event')
            ->andWhere('s.status = :status')
            ->setParameter('event', $event)
            ->setParameter('status', EventNotificationStatus::Confirmed)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function countByEvent(Event $event): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.event = :event')
            ->setParameter('event', $event)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
