<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\EventNotificationStatus;
use App\Entity\EventNotificationSubscription;
use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Repository\EventNotificationSubscriptionRepository;
use App\Service\Mail\DsnVault;
use App\Tests\Mail\CapturedMail;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EventNotificationControllerTest extends WebTestCase
{
    /** Host the organizer DSN resolves to via the test DNS stub (PrebakedDnsResolver). */
    private const string ORGANIZER_MAIL_HOST = '93.184.216.34';

    protected function setUp(): void
    {
        CapturedMail::reset();
    }

    public function testSignupSendsConfirmationEmail(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $event = $this->makeEventWithMail($em, 'notify-event');

        $client->request(
            Request::METHOD_POST,
            '/e/notify-event/notify',
            [
                'email' => 'visitor@example.com',
                'website' => '',
            ]
        );

        self::assertResponseIsSuccessful();
        $this->assertCount(1, CapturedMail::messagesForHost(self::ORGANIZER_MAIL_HOST));

        /** @var EventNotificationSubscriptionRepository $repo */
        $repo = self::getContainer()->get(EventNotificationSubscriptionRepository::class);
        $sub = $repo->findOneByEventAndEmail($event, 'visitor@example.com');
        $this->assertInstanceOf(EventNotificationSubscription::class, $sub);
        $this->assertSame(EventNotificationStatus::Pending, $sub->getStatus());
    }

    public function testHoneypotDropsSilently(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->makeEventWithMail($em, 'honeypot-event');

        $client->request(
            Request::METHOD_POST,
            '/e/honeypot-event/notify',
            [
                'email' => 'bot@example.com',
                'website' => 'http://spam.example',
            ]
        );

        self::assertResponseIsSuccessful();
        $this->assertCount(0, CapturedMail::messagesForHost(self::ORGANIZER_MAIL_HOST));

        /** @var EventNotificationSubscriptionRepository $repo */
        $repo = self::getContainer()->get(EventNotificationSubscriptionRepository::class);
        /** @var Event $event */
        $event = $em->getRepository(Event::class)->findOneBy(['slug' => 'honeypot-event']);
        $this->assertNotInstanceOf(
            EventNotificationSubscription::class,
            $repo->findOneByEventAndEmail($event, 'bot@example.com')
        );
    }

    public function testConfirmedResubmitSendsNoEmail(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $event = $this->makeEventWithMail($em, 'resub-event');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $sub = new EventNotificationSubscription($event, 'already@example.com', $now);
        $sub->confirm($now);

        $em->persist($sub);
        $em->flush();

        $client->request(
            Request::METHOD_POST,
            '/e/resub-event/notify',
            [
                'email' => 'already@example.com',
                'website' => '',
            ]
        );

        self::assertResponseIsSuccessful();
        $this->assertCount(0, CapturedMail::messagesForHost(self::ORGANIZER_MAIL_HOST));
    }

    public function testConfirmTokenConfirms(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $event = $this->makeEventWithMail($em, 'confirm-event');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $sub = new EventNotificationSubscription($event, 'c@example.com', $now);
        $token = (string)$sub->getConfirmationToken();
        $em->persist($sub);
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/confirm-event/notify/confirm/' . $token);
        self::assertResponseIsSuccessful();

        $em->clear();
        /** @var EventNotificationSubscriptionRepository $repo */
        $repo = self::getContainer()->get(EventNotificationSubscriptionRepository::class);
        /** @var Event $reloadedEvent */
        $reloadedEvent = $em->getRepository(Event::class)->findOneBy(['slug' => 'confirm-event']);
        $reloaded = $repo->findOneByEventAndEmail($reloadedEvent, 'c@example.com');
        $this->assertInstanceOf(EventNotificationSubscription::class, $reloaded);
        $this->assertSame(EventNotificationStatus::Confirmed, $reloaded->getStatus());
    }

    public function testInvalidConfirmTokenShowsInvalidPage(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->makeEventWithMail($em, 'badtoken-event');

        $client->request(Request::METHOD_GET, '/e/badtoken-event/notify/confirm/does-not-exist');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'invalid');
    }

    private function makeEventWithMail(EntityManagerInterface $em, string $slug): Event
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
        $em->flush();

        return $event;
    }
}
