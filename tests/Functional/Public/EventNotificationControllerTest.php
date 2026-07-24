<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\EventNotificationStatus;
use App\Entity\EventNotificationSubscription;
use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Message\SendSubscriptionConfirmationEmail;
use App\Repository\EventNotificationSubscriptionRepository;
use App\Service\Mail\DsnVault;
use App\Tests\Mail\CapturedMail;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class EventNotificationControllerTest extends WebTestCase
{
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

        $sent = $this->asyncTransport()->getSent();
        $this->assertCount(1, $sent);
        $this->assertInstanceOf(SendSubscriptionConfirmationEmail::class, $sent[0]->getMessage());

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
        $this->assertCount(0, $this->asyncTransport()->getSent());

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
        $this->assertCount(0, $this->asyncTransport()->getSent());
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

    public function testDoubleConfirmShowsConfirmedPageAgain(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $event = $this->makeEventWithMail($em, 'double-confirm-event');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $sub = new EventNotificationSubscription($event, 'd@example.com', $now);
        $token = (string) $sub->getConfirmationToken();
        $em->persist($sub);
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/double-confirm-event/notify/confirm/' . $token);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'confirmed');

        // Second tap of the SAME link — must not fall into the invalid page.
        $client->request(Request::METHOD_GET, '/e/double-confirm-event/notify/confirm/' . $token);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'confirmed');

        $em->clear();
        /** @var EventNotificationSubscriptionRepository $repo */
        $repo = self::getContainer()->get(EventNotificationSubscriptionRepository::class);
        /** @var Event $reloadedEvent */
        $reloadedEvent = $em->getRepository(Event::class)->findOneBy(['slug' => 'double-confirm-event']);
        $reloaded = $repo->findOneByEventAndEmail($reloadedEvent, 'd@example.com');
        $this->assertInstanceOf(EventNotificationSubscription::class, $reloaded);
        $this->assertSame(EventNotificationStatus::Confirmed, $reloaded->getStatus());
    }

    public function testExpiredConfirmTokenShowsTimedOutPage(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $event = $this->makeEventWithMail($em, 'timed-out-event');
        // createdAt 8 days ago -> confirmationExpiresAt (createdAt + 7 days) is in the past.
        $createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'))->modify('-8 days');
        $sub = new EventNotificationSubscription($event, 't@example.com', $createdAt);
        $token = (string) $sub->getConfirmationToken();
        $em->persist($sub);
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/timed-out-event/notify/confirm/' . $token);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'timed out');

        $em->clear();
        /** @var EventNotificationSubscriptionRepository $repo */
        $repo = self::getContainer()->get(EventNotificationSubscriptionRepository::class);
        /** @var Event $reloadedEvent */
        $reloadedEvent = $em->getRepository(Event::class)->findOneBy(['slug' => 'timed-out-event']);
        $reloaded = $repo->findOneByEventAndEmail($reloadedEvent, 't@example.com');
        $this->assertInstanceOf(EventNotificationSubscription::class, $reloaded);
        $this->assertSame(EventNotificationStatus::Pending, $reloaded->getStatus());
    }

    private function asyncTransport(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');

        return $transport;
    }

    private function makeEventWithMail(EntityManagerInterface $em, string $slug): Event
    {
        // The confirm_email_resend limiter lives in a filesystem-backed pool that
        // persists across runs; clear it so repeated local runs within the 10-min
        // window don't suppress the dispatch these tests assert on. Called after
        // createClient() has booted the kernel, so getContainer() is safe here.
        $pool = self::getContainer()->get('rate_limiter.cache_pool');
        if ($pool instanceof CacheItemPoolInterface) {
            $pool->clear();
        }

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
