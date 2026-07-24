<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Entity\Event;
use App\Entity\EventNotificationSubscription;
use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Message\SendSubscriptionConfirmationEmail;
use App\MessageHandler\SendSubscriptionConfirmationEmailHandler;
use App\Service\Mail\DsnVault;
use App\Tests\Mail\CapturedMail;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SendSubscriptionConfirmationEmailHandlerTest extends KernelTestCase
{
    private const string ORGANIZER_MAIL_HOST = '93.184.216.34';

    protected function setUp(): void
    {
        CapturedMail::reset();
    }

    public function testPendingSubscriptionGetsOneEmail(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        [, $sub] = $this->makePending($em, 'confirm-async-a');

        $this->handler()(new SendSubscriptionConfirmationEmail((int) $sub->getId()));

        $this->assertCount(1, CapturedMail::messagesForHost(self::ORGANIZER_MAIL_HOST));
    }

    public function testConfirmedSubscriptionIsSkipped(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        [, $sub] = $this->makePending($em, 'confirm-async-b');
        $sub->confirm(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $em->flush();

        $this->handler()(new SendSubscriptionConfirmationEmail((int) $sub->getId()));

        $this->assertCount(0, CapturedMail::messagesForHost(self::ORGANIZER_MAIL_HOST));
    }

    public function testMissingSubscriptionIsNoOp(): void
    {
        self::bootKernel();

        $this->handler()(new SendSubscriptionConfirmationEmail(999999));

        $this->assertCount(0, CapturedMail::messagesForHost(self::ORGANIZER_MAIL_HOST));
    }

    private function handler(): SendSubscriptionConfirmationEmailHandler
    {
        /** @var SendSubscriptionConfirmationEmailHandler $handler */
        $handler = self::getContainer()->get(SendSubscriptionConfirmationEmailHandler::class);

        return $handler;
    }

    /** @return array{Event, EventNotificationSubscription} */
    private function makePending(EntityManagerInterface $em, string $slug): array
    {
        $owner = new User($slug . '-owner@example.com', 'Owner');
        $owner->addRole('ROLE_ORGANIZER');

        $em->persist($owner);

        /** @var DsnVault $vault */
        $vault = self::getContainer()->get(DsnVault::class);
        $config = new UserMailConfig(
            $owner,
            $vault->encrypt('smtp://x@smtp.example-organizer.test:25'),
            $slug . '-owner@example.com',
            null,
        );
        $config->markVerified();

        $em->persist($config);

        $event = new Event(
            slug: $slug,
            name: 'Event',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: $owner,
        );
        $event->enableNotifications();

        $em->persist($event);

        $sub = new EventNotificationSubscription(
            $event,
            'visitor@example.com',
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        $em->persist($sub);
        $em->flush();

        return [$event, $sub];
    }
}
