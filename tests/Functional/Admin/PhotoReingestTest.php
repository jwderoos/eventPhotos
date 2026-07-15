<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\PhotoStatus;
use App\Entity\User;
use App\Message\ProcessPhoto;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

final class PhotoReingestTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $owner;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $c = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);

        $this->em = $em;

        $owner = new User('o@example.test', 'O');
        $owner->setPassword($hasher->hashPassword($owner, 'x'));
        $owner->addRole('ROLE_ORGANIZER');

        $this->em->persist($owner);
        $this->em->flush();

        $this->owner = $owner;
        $this->client->loginUser($owner);
    }

    private function makeEvent(bool $retainOriginals): Event
    {
        $event = new Event(
            'e' . bin2hex(random_bytes(4)),
            'E',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $this->owner,
        );
        $event->setTimezone('UTC');
        $event->setRetainOriginals($retainOriginals);

        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    private function addReady(Event $event, string $hashSeed): Photo
    {
        $photo = new Photo($event, str_pad($hashSeed, 64, '0'), $hashSeed . '.jpg', 100);
        $photo->markReady(new DateTimeImmutable('now', new DateTimeZone('UTC')), 100, 100, 2048);

        $this->em->persist($photo);
        $this->em->flush();

        return $photo;
    }

    /** @return list<ProcessPhoto> */
    private function dispatched(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');

        $messages = [];
        foreach ($transport->getSent() as $envelope) {
            $msg = $envelope->getMessage();
            $this->assertInstanceOf(ProcessPhoto::class, $msg);
            $messages[] = $msg;
        }

        return $messages;
    }

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

    public function testReingestAllResetsReadyPhotosAndDispatches(): void
    {
        $event = $this->makeEvent(retainOriginals: true);
        $ready1 = $this->addReady($event, 'aa');
        $ready2 = $this->addReady($event, 'bb');
        $pending = new Photo($event, str_pad('cc', 64, '0'), 'cc.jpg', 100);
        $this->em->persist($pending);
        $this->em->flush();

        $token = $this->primeCsrfToken('reingest_all_photos_' . $event->getId());
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/reingest', (int) $event->getId()),
            ['_token' => $token],
        );

        self::assertResponseRedirects(sprintf('/admin/events/%d/photos-grid', (int) $event->getId()));

        $this->em->clear();
        /** @var Photo $reloaded1 */
        $reloaded1 = $this->em->find(Photo::class, $ready1->getId());
        /** @var Photo $reloaded2 */
        $reloaded2 = $this->em->find(Photo::class, $ready2->getId());
        $this->assertSame(PhotoStatus::Pending, $reloaded1->getStatus());
        $this->assertSame(PhotoStatus::Pending, $reloaded2->getStatus());

        $messages = $this->dispatched();
        $this->assertCount(2, $messages, 'Only the two Ready photos are re-dispatched, not the Pending one.');
        foreach ($messages as $m) {
            $this->assertTrue($m->reingest, 'Bulk re-ingest must dispatch with reingest: true.');
        }
    }

    public function testReingestAllRefusedWhenNotRetainingOriginals(): void
    {
        $event = $this->makeEvent(retainOriginals: false);
        $ready = $this->addReady($event, 'aa');

        $token = $this->primeCsrfToken('reingest_all_photos_' . $event->getId());
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/reingest', (int) $event->getId()),
            ['_token' => $token],
        );

        self::assertResponseRedirects(sprintf('/admin/events/%d/photos-grid', (int) $event->getId()));
        $this->em->clear();
        /** @var Photo $reloaded */
        $reloaded = $this->em->find(Photo::class, $ready->getId());
        $this->assertSame(PhotoStatus::Ready, $reloaded->getStatus());
        $this->assertCount(0, $this->dispatched());
    }

    public function testReingestAllRejectsMissingCsrf(): void
    {
        $event = $this->makeEvent(retainOriginals: true);

        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/reingest', (int) $event->getId()),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testReingestSinglePhoto(): void
    {
        $event = $this->makeEvent(retainOriginals: true);
        $ready = $this->addReady($event, 'aa');

        $token = $this->primeCsrfToken('reingest_photo_' . $ready->getId());
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/%d/reingest', (int) $event->getId(), (int) $ready->getId()),
            ['_token' => $token],
        );

        self::assertResponseRedirects(sprintf('/admin/events/%d/photos-grid?page=1', (int) $event->getId()));
        $this->em->clear();
        /** @var Photo $reloaded */
        $reloaded = $this->em->find(Photo::class, $ready->getId());
        $this->assertSame(PhotoStatus::Pending, $reloaded->getStatus());

        $messages = $this->dispatched();
        $this->assertCount(1, $messages);
        $this->assertTrue($messages[0]->reingest);
    }

    public function testReingestSingleRefusedWhenNotReady(): void
    {
        $event = $this->makeEvent(retainOriginals: true);
        $pending = new Photo($event, str_pad('dd', 64, '0'), 'dd.jpg', 100);
        $this->em->persist($pending);
        $this->em->flush();

        $token = $this->primeCsrfToken('reingest_photo_' . $pending->getId());
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/%d/reingest', (int) $event->getId(), (int) $pending->getId()),
            ['_token' => $token],
        );

        self::assertResponseRedirects(sprintf('/admin/events/%d/photos-grid?page=1', (int) $event->getId()));
        $this->em->clear();
        /** @var Photo $reloaded */
        $reloaded = $this->em->find(Photo::class, $pending->getId());
        $this->assertSame(PhotoStatus::Pending, $reloaded->getStatus());
        $this->assertCount(0, $this->dispatched());
    }

    public function testReingestSingleRefusedWhenNotRetainingOriginals(): void
    {
        $event = $this->makeEvent(retainOriginals: false);
        $ready = $this->addReady($event, 'aa');

        $token = $this->primeCsrfToken('reingest_photo_' . $ready->getId());
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/%d/reingest', (int) $event->getId(), (int) $ready->getId()),
            ['_token' => $token],
        );

        self::assertResponseRedirects(sprintf('/admin/events/%d/photos-grid?page=1', (int) $event->getId()));
        $this->em->clear();
        /** @var Photo $reloaded */
        $reloaded = $this->em->find(Photo::class, $ready->getId());
        $this->assertSame(PhotoStatus::Ready, $reloaded->getStatus());
        $this->assertCount(0, $this->dispatched());
    }

    public function testReingestSingleRejectsMissingCsrf(): void
    {
        $event = $this->makeEvent(retainOriginals: true);
        $ready = $this->addReady($event, 'aa');

        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/%d/reingest', (int) $event->getId(), (int) $ready->getId()),
        );

        self::assertResponseStatusCodeSame(403);
    }
}
