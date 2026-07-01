<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Message\SendEventLiveNotifications;
use App\Service\Mail\DsnVault;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

final class EventPublishTest extends WebTestCase
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

    public function testPublishRejectedWithoutReadyPhoto(): void
    {
        [$owner, $event] = $this->makeOrganizerAndEvent('pub-noready', withMail: true);
        $this->client->loginUser($owner);

        $this->client->request(
            Request::METHOD_POST,
            '/admin/events/' . $event->getId() . '/publish',
            [
                '_token' => $this->primeCsrfToken('publish' . $event->getId()),
            ]
        );

        self::assertResponseStatusCodeSame(422);
        $this->em->clear();
        $this->assertFalse($this->reloadEvent($event->getId())->isPublished());
    }

    public function testHappyPublishDispatchesFanOut(): void
    {
        [$owner, $event] = $this->makeOrganizerAndEvent('pub-happy', withMail: true);
        $this->addReadyPhoto($event);
        $this->client->loginUser($owner);

        $this->client->request(
            Request::METHOD_POST,
            '/admin/events/' . $event->getId() . '/publish',
            [
                '_token' => $this->primeCsrfToken('publish' . $event->getId()),
            ]
        );

        self::assertResponseRedirects();
        $this->em->clear();
        $this->assertTrue($this->reloadEvent($event->getId())->isPublished());

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $messages = array_map(static fn(Envelope $e): object => $e->getMessage(), $transport->getSent());
        $this->assertContainsOnlyInstancesOf(SendEventLiveNotifications::class, $messages);
        $this->assertCount(1, $messages);
    }

    public function testPublishRejectedWithInvalidCsrf(): void
    {
        [$owner, $event] = $this->makeOrganizerAndEvent('pub-csrf', withMail: true);
        $this->addReadyPhoto($event);
        $this->client->loginUser($owner);

        $this->client->request(
            Request::METHOD_POST,
            '/admin/events/' . $event->getId() . '/publish',
            ['_token' => 'bogus']
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testPublishBlockedWithoutActiveMail(): void
    {
        [$owner, $event] = $this->makeOrganizerAndEvent('pub-nomail', withMail: false);
        $this->addReadyPhoto($event);
        $this->client->loginUser($owner);

        $this->client->request(
            Request::METHOD_POST,
            '/admin/events/' . $event->getId() . '/publish',
            [
                '_token' => $this->primeCsrfToken('publish' . $event->getId()),
            ]
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testNewFormHidesCheckboxWithoutActiveMail(): void
    {
        $owner = $this->makeOrganizer('new-nomail', withMail: false);
        $this->client->loginUser($owner);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/events/new');
        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('input[name="event[notificationsEnabled]"]'));
    }

    public function testNewFormShowsCheckboxUncheckedWithActiveMail(): void
    {
        $owner = $this->makeOrganizer('new-mail', withMail: true);
        $this->client->loginUser($owner);

        $crawler  = $this->client->request(Request::METHOD_GET, '/admin/events/new');
        self::assertResponseIsSuccessful();
        $checkbox = $crawler->filter('input[name="event[notificationsEnabled]"]');
        $this->assertCount(1, $checkbox);
        $this->assertNull($checkbox->attr('checked'));

        $form = $crawler->selectButton('Create')->form([
            'event[name]'                  => 'Notify Event',
            'event[eventDate]'             => '2026-07-15',
            'event[startTime]'             => '10:00',
            'event[endTime]'               => '14:00',
            'event[timezone]'              => 'Europe/Amsterdam',
            'event[notificationsEnabled]'  => '1',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/events');

        $created = $this->em->getRepository(Event::class)->findOneBy(['name' => 'Notify Event']);
        $this->assertInstanceOf(Event::class, $created);
        $this->assertTrue($created->areNotificationsEnabled());
    }

    public function testEditCheckboxTogglesFlagBothWays(): void
    {
        [$owner, $event] = $this->makeOrganizerAndEvent('edit-toggle', withMail: true);
        $event->disableNotifications();
        $this->em->flush();
        $this->client->loginUser($owner);

        // Enable via the checkbox.
        $crawler = $this->client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', (int) $event->getId()));
        $form    = $crawler->selectButton('Save')->form();
        /** @phpstan-ignore-next-line method.nonObject */
        $form['event[notificationsEnabled]']->tick();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/events');

        $this->em->clear();
        $this->assertTrue($this->reloadEvent($event->getId())->areNotificationsEnabled());

        // Disable via the checkbox.
        $crawler = $this->client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', (int) $event->getId()));
        $form    = $crawler->selectButton('Save')->form();
        /** @phpstan-ignore-next-line method.nonObject */
        $form['event[notificationsEnabled]']->untick();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/events');

        $this->em->clear();
        $this->assertFalse($this->reloadEvent($event->getId())->areNotificationsEnabled());
    }

    public function testEditWithoutActiveMailPreservesExistingFlag(): void
    {
        // An event whose flag was enabled while mail was active, then mail lapsed.
        [$owner, $event] = $this->makeOrganizerAndEvent('edit-nomail', withMail: false);
        $event->enableNotifications();
        $this->em->flush();
        $this->client->loginUser($owner);

        $crawler = $this->client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', (int) $event->getId()));
        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('input[name="event[notificationsEnabled]"]'));

        // Submitting the form (no checkbox present) must not clear the stored flag.
        $form = $crawler->selectButton('Save')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/events');

        $this->em->clear();
        $this->assertTrue($this->reloadEvent($event->getId())->areNotificationsEnabled());
    }

    private function reloadEvent(?int $id): Event
    {
        $event = $this->em->getRepository(Event::class)->find($id);
        $this->assertInstanceOf(Event::class, $event);

        return $event;
    }

    private function addReadyPhoto(Event $event): void
    {
        $photo = new Photo(
            event: $event,
            contentHash: str_repeat('a', 64),
            originalFilename: 'IMG_0001.jpg',
            byteSize: 1_234_567,
        );
        $photo->markReady(new DateTimeImmutable('2026-06-10 12:00:00', new DateTimeZone('UTC')), 4032, 3024, 274_000);

        $this->em->persist($photo);
        $this->em->flush();
    }

    private function makeOrganizer(string $slug, bool $withMail): User
    {
        $owner = new User($slug . '-owner@example.com', 'Owner');
        $owner->addRole('ROLE_ORGANIZER');

        $this->em->persist($owner);

        if ($withMail) {
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
        }

        $this->em->flush();

        return $owner;
    }

    /**
     * @return array{0: User, 1: Event}
     */
    private function makeOrganizerAndEvent(string $slug, bool $withMail): array
    {
        $owner = $this->makeOrganizer($slug, $withMail);

        $event = new Event(
            slug: $slug,
            name: 'Event',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: $owner,
        );
        $event->enableNotifications();

        $this->em->persist($event);
        $this->em->flush();

        return [$owner, $event];
    }

    /**
     * Boots a session for the logged-in client and writes a known CSRF token under
     * the fallback session-token namespace that isCsrfTokenValid() consults.
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
}
