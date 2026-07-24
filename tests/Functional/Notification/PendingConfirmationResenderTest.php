<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Entity\Event;
use App\Entity\EventNotificationStatus;
use App\Entity\EventNotificationSubscription;
use App\Entity\User;
use App\Message\SendSubscriptionConfirmationEmail;
use App\Service\Notification\PendingConfirmationResender;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class PendingConfirmationResenderTest extends KernelTestCase
{
    public function testResendsOnlyPendingWithFreshTokensAndSpacedDispatch(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $owner = new User('resend-owner@example.com', 'Owner');
        $em->persist($owner);
        $event = new Event(
            slug: 'resend-service-event',
            name: 'Resend',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: $owner,
        );
        $em->persist($event);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $pendingA = new EventNotificationSubscription($event, 'pa@example.com', $now);
        $pendingB = new EventNotificationSubscription($event, 'pb@example.com', $now);
        $tokenBefore = $pendingA->getConfirmationToken();
        $em->persist($pendingA);
        $em->persist($pendingB);

        $confirmed = new EventNotificationSubscription($event, 'conf@example.com', $now);
        $confirmed->confirm($now);

        $em->persist($confirmed);

        $unsub = new EventNotificationSubscription($event, 'unsub@example.com', $now);
        $unsub->unsubscribe($now);

        $em->persist($unsub);
        $em->flush();

        /** @var PendingConfirmationResender $resender */
        $resender = $container->get(PendingConfirmationResender::class);
        $count = $resender->resendAll($event);

        $this->assertSame(2, $count);

        $em->refresh($pendingA);
        $this->assertNotSame($tokenBefore, $pendingA->getConfirmationToken());
        $this->assertSame(EventNotificationStatus::Pending, $pendingA->getStatus());

        $em->refresh($confirmed);
        $this->assertSame(EventNotificationStatus::Confirmed, $confirmed->getStatus());
        $em->refresh($unsub);
        $this->assertSame(EventNotificationStatus::Unsubscribed, $unsub->getStatus());

        /** @var InMemoryTransport $transport */
        $transport = $container->get('messenger.transport.async');
        $sent = $transport->getSent();
        $this->assertCount(2, $sent);

        $previousDelay = -1;
        foreach ($sent as $envelope) {
            $this->assertInstanceOf(SendSubscriptionConfirmationEmail::class, $envelope->getMessage());
            /** @var DelayStamp|null $stamp */
            $stamp = $envelope->last(DelayStamp::class);
            $this->assertInstanceOf(DelayStamp::class, $stamp);
            $this->assertGreaterThan($previousDelay, $stamp->getDelay());
            $previousDelay = $stamp->getDelay();
        }
    }
}
