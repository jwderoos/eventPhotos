<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use App\Entity\Event;
use App\Entity\EventNotificationSubscription;
use App\Entity\User;
use App\Repository\EventNotificationSubscriptionRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EventNotificationSubscriptionRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private EventNotificationSubscriptionRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var EventNotificationSubscriptionRepository $repo */
        $repo = $container->get(EventNotificationSubscriptionRepository::class);
        $this->em = $em;
        $this->repo = $repo;
    }

    public function testFindOneByEventAndEmailIsCaseInsensitive(): void
    {
        $event = $this->persistEvent('case-event');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $sub = new EventNotificationSubscription($event, 'Person@Example.com', $now);
        $this->em->persist($sub);
        $this->em->flush();

        $found = $this->repo->findOneByEventAndEmail($event, 'PERSON@EXAMPLE.COM');

        $this->assertInstanceOf(EventNotificationSubscription::class, $found);
        $this->assertSame($sub->getId(), $found->getId());
    }

    public function testCountAndConfirmedQuery(): void
    {
        $event = $this->persistEvent('count-event');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $confirmed = new EventNotificationSubscription($event, 'a@example.com', $now);
        $confirmed->confirm($now);

        $pending = new EventNotificationSubscription($event, 'b@example.com', $now);
        $this->em->persist($confirmed);
        $this->em->persist($pending);
        $this->em->flush();

        $this->assertSame(2, $this->repo->countByEvent($event));
        $confirmedList = $this->repo->findConfirmedByEvent($event);
        $this->assertCount(1, $confirmedList);
        $this->assertSame('a@example.com', $confirmedList[0]->getEmail());
    }

    public function testUniqueConstraintPerEventEmail(): void
    {
        $event = $this->persistEvent('unique-event');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->em->persist(new EventNotificationSubscription($event, 'dup@example.com', $now));
        $this->em->flush();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->persist(new EventNotificationSubscription($event, 'DUP@example.com', $now));
        $this->em->flush();
    }

    private function persistEvent(string $slug): Event
    {
        $owner = new User($slug . '-owner@example.com', 'Owner');
        $this->em->persist($owner);
        $event = new Event(
            slug: $slug,
            name: 'Event',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: $owner,
        );
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }
}
