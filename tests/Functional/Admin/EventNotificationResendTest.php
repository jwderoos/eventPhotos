<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\EventNotificationSubscription;
use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Message\SendSubscriptionConfirmationEmail;
use App\Service\Mail\DsnVault;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

final class EventNotificationResendTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em = $em;
    }

    public function testResendButtonNudgesOnlyPending(): void
    {
        [$owner, $event] = $this->makeEvent('resend-dash-event');

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->em->persist(new EventNotificationSubscription($event, 'p1@example.com', $now));
        $this->em->persist(new EventNotificationSubscription($event, 'p2@example.com', $now));

        $confirmed = new EventNotificationSubscription($event, 'c@example.com', $now);
        $confirmed->confirm($now);

        $this->em->persist($confirmed);
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/admin/events/' . $event->getId() . '/edit');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Re-send confirmation to 2 unverified subscriber(s)');
        self::assertResponseRedirects('/admin/events/' . $event->getId() . '/edit');

        $sent = $this->asyncTransport()->getSent();
        $this->assertCount(2, $sent);
        $this->assertInstanceOf(SendSubscriptionConfirmationEmail::class, $sent[0]->getMessage());
    }

    public function testResendBlockedAfterPublish(): void
    {
        [$owner, $event] = $this->makeEvent('resend-published-event');
        $event->markPublished(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $this->em->persist(new EventNotificationSubscription(
            $event,
            'p@example.com',
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        ));
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request(
            Request::METHOD_POST,
            '/admin/events/' . $event->getId() . '/notify/resend-pending',
            ['_token' => $this->primeCsrfToken('resend_pending_' . $event->getId())],
        );

        self::assertResponseRedirects('/admin/events/' . $event->getId() . '/edit');
        $this->assertCount(0, $this->asyncTransport()->getSent());
    }

    public function testResendRejectsBadCsrf(): void
    {
        [$owner, $event] = $this->makeEvent('resend-csrf-event');
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request(
            Request::METHOD_POST,
            '/admin/events/' . $event->getId() . '/notify/resend-pending',
            ['_token' => 'wrong'],
        );

        self::assertResponseStatusCodeSame(403);
    }

    private function asyncTransport(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');

        return $transport;
    }

    /**
     * Writes a known CSRF token under the fallback session-token namespace that
     * isCsrfTokenValid() consults.
     */
    private function primeCsrfToken(string $tokenId): string
    {
        $this->client->request(Request::METHOD_GET, '/admin/events');

        $session = $this->client->getRequest()->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }

        $token = bin2hex(random_bytes(16));
        $session->set(SessionTokenStorage::SESSION_NAMESPACE . '/' . $tokenId, $token);
        $session->save();

        return $token;
    }

    /** @return array{User, Event} */
    private function makeEvent(string $slug): array
    {
        $owner = new User($slug . '-owner@example.com', 'Owner');
        $owner->addRole('ROLE_ORGANIZER');

        $this->em->persist($owner);

        /** @var DsnVault $vault */
        $vault = self::getContainer()->get(DsnVault::class);
        $config = new UserMailConfig(
            $owner,
            $vault->encrypt('smtp://x@smtp.example-organizer.test:25'),
            $slug . '-owner@example.com',
            null,
        );
        $config->markVerified();

        $this->em->persist($config);

        $event = new Event(
            slug: $slug,
            name: 'Event',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: $owner,
        );
        $event->enableNotifications();

        $this->em->persist($event);

        return [$owner, $event];
    }
}
