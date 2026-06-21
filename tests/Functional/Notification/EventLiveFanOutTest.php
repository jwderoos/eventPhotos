<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Entity\Event;
use App\Entity\EventNotificationSubscription;
use App\Entity\User;
use App\Message\SendEventLiveEmail;
use App\Message\SendEventLiveNotifications;
use App\MessageHandler\SendEventLiveNotificationsHandler;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class EventLiveFanOutTest extends KernelTestCase
{
    public function testFanOutDispatchesOneDelayedMessagePerConfirmedSubscriber(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $owner = new User('fanout-owner@example.com', 'Owner');
        $em->persist($owner);
        $event = new Event(
            slug: 'fanout-event',
            name: 'Fan Out',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: $owner,
        );
        $event->markPublished(new DateTimeImmutable('now', new DateTimeZone('UTC')));

        $em->persist($event);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        for ($i = 0; $i < 3; ++$i) {
            $sub = new EventNotificationSubscription($event, sprintf('sub%d@example.com', $i), $now);
            $sub->confirm($now);
            $em->persist($sub);
        }

        $em->flush();

        /** @var SendEventLiveNotificationsHandler $handler */
        $handler = $container->get(SendEventLiveNotificationsHandler::class);
        $eventId = $event->getId();
        $this->assertNotNull($eventId);
        $handler(new SendEventLiveNotifications($eventId));

        /** @var InMemoryTransport $transport */
        $transport = $container->get('messenger.transport.async');
        $sent = $transport->getSent();

        $this->assertCount(3, $sent);
        $previousDelay = -1;
        foreach ($sent as $envelope) {
            $this->assertInstanceOf(SendEventLiveEmail::class, $envelope->getMessage());
            /** @var DelayStamp|null $stamp */
            $stamp = $envelope->last(DelayStamp::class);
            $this->assertInstanceOf(DelayStamp::class, $stamp);
            $this->assertGreaterThan($previousDelay, $stamp->getDelay());
            $previousDelay = $stamp->getDelay();
        }
    }
}
