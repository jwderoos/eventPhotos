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

final class PhotoRetryTest extends WebTestCase
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

    private function addFailed(Event $event, string $hashSeed, string $reason): Photo
    {
        $photo = new Photo($event, str_pad($hashSeed, 64, '0'), $hashSeed . '.jpg', 100);
        $photo->markFailed($reason);

        $this->em->persist($photo);
        $this->em->flush();

        return $photo;
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

    public function testRetryResetsFailedPhotoAndDispatchesFreshIngest(): void
    {
        $event = $this->makeEvent(retainOriginals: true);
        $failed = $this->addFailed($event, 'aa', 'EXIF DateTimeOriginal is missing.');

        $token = $this->primeCsrfToken('retry_photo_' . $failed->getId());
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/%d/retry', (int) $event->getId(), (int) $failed->getId()),
            ['_token' => $token],
        );

        self::assertResponseRedirects(sprintf('/admin/events/%d/photos-grid?page=1', (int) $event->getId()));

        $this->em->clear();
        /** @var Photo $reloaded */
        $reloaded = $this->em->find(Photo::class, $failed->getId());
        $this->assertSame(PhotoStatus::Pending, $reloaded->getStatus());
        $this->assertNull($reloaded->getProcessingError());

        $messages = $this->dispatched();
        $this->assertCount(1, $messages);
        $this->assertFalse(
            $messages[0]->reingest,
            'A retry is a fresh ingest attempt, so the ingest window guard must apply (reingest: false).',
        );
    }

    public function testRetryRefusedWhenNotRetainingOriginals(): void
    {
        $event = $this->makeEvent(retainOriginals: false);
        $failed = $this->addFailed($event, 'aa', 'boom');

        $token = $this->primeCsrfToken('retry_photo_' . $failed->getId());
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/%d/retry', (int) $event->getId(), (int) $failed->getId()),
            ['_token' => $token],
        );

        self::assertResponseRedirects(sprintf('/admin/events/%d/photos-grid?page=1', (int) $event->getId()));
        $this->em->clear();
        /** @var Photo $reloaded */
        $reloaded = $this->em->find(Photo::class, $failed->getId());
        $this->assertSame(PhotoStatus::Failed, $reloaded->getStatus());
        $this->assertCount(0, $this->dispatched());
    }

    public function testRetryRefusedWhenNotFailed(): void
    {
        $event = $this->makeEvent(retainOriginals: true);
        $ready = $this->addReady($event, 'aa');

        $token = $this->primeCsrfToken('retry_photo_' . $ready->getId());
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/%d/retry', (int) $event->getId(), (int) $ready->getId()),
            ['_token' => $token],
        );

        self::assertResponseRedirects(sprintf('/admin/events/%d/photos-grid?page=1', (int) $event->getId()));
        $this->em->clear();
        /** @var Photo $reloaded */
        $reloaded = $this->em->find(Photo::class, $ready->getId());
        $this->assertSame(PhotoStatus::Ready, $reloaded->getStatus());
        $this->assertCount(0, $this->dispatched());
    }

    public function testRetryRejectsMissingCsrf(): void
    {
        $event = $this->makeEvent(retainOriginals: true);
        $failed = $this->addFailed($event, 'aa', 'boom');

        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/%d/retry', (int) $event->getId(), (int) $failed->getId()),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testRetryAllResetsFailedPhotosAndDispatchesFreshIngest(): void
    {
        $event   = $this->makeEvent(retainOriginals: true);
        $failed1 = $this->addFailed($event, 'aa', 'boom');
        $failed2 = $this->addFailed($event, 'bb', 'boom');
        $ready   = $this->addReady($event, 'cc');

        $token = $this->primeCsrfToken('retry_all_photos_' . $event->getId());
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/retry-all', (int) $event->getId()),
            ['_token' => $token],
        );

        self::assertResponseRedirects(sprintf('/admin/events/%d/photos-grid', (int) $event->getId()));

        $this->em->clear();
        /** @var Photo $reloaded1 */
        $reloaded1 = $this->em->find(Photo::class, $failed1->getId());
        /** @var Photo $reloaded2 */
        $reloaded2 = $this->em->find(Photo::class, $failed2->getId());
        /** @var Photo $reloadedReady */
        $reloadedReady = $this->em->find(Photo::class, $ready->getId());
        $this->assertSame(PhotoStatus::Pending, $reloaded1->getStatus());
        $this->assertSame(PhotoStatus::Pending, $reloaded2->getStatus());
        $this->assertSame(PhotoStatus::Ready, $reloadedReady->getStatus(), 'Only Failed photos are retried.');

        $messages = $this->dispatched();
        $this->assertCount(2, $messages, 'Only the two Failed photos are re-dispatched.');
        foreach ($messages as $m) {
            $this->assertFalse($m->reingest, 'Bulk retry is a fresh ingest attempt (reingest: false).');
        }
    }

    public function testRetryAllRefusedWhenNotRetainingOriginals(): void
    {
        $event  = $this->makeEvent(retainOriginals: false);
        $failed = $this->addFailed($event, 'aa', 'boom');

        $token = $this->primeCsrfToken('retry_all_photos_' . $event->getId());
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/retry-all', (int) $event->getId()),
            ['_token' => $token],
        );

        self::assertResponseRedirects(sprintf('/admin/events/%d/photos-grid', (int) $event->getId()));
        $this->em->clear();
        /** @var Photo $reloaded */
        $reloaded = $this->em->find(Photo::class, $failed->getId());
        $this->assertSame(PhotoStatus::Failed, $reloaded->getStatus());
        $this->assertCount(0, $this->dispatched());
    }

    public function testRetryAllRejectsMissingCsrf(): void
    {
        $event = $this->makeEvent(retainOriginals: true);

        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/retry-all', (int) $event->getId()),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testFailedPhotoShowsErrorMessageInGrid(): void
    {
        $event = $this->makeEvent(retainOriginals: false);
        $this->addFailed($event, 'aa', 'EXIF DateTimeOriginal is missing (no EXIF data found).');

        $this->client->request(
            Request::METHOD_GET,
            sprintf('/admin/events/%d/photos-grid', (int) $event->getId()),
        );

        self::assertResponseIsSuccessful();
        // Must be rendered as visible text, not merely a hover tooltip on the status badge.
        self::assertSelectorTextContains(
            '[data-role="ingest-error"]',
            'EXIF DateTimeOriginal is missing (no EXIF data found).',
        );
    }

    public function testRetryAllButtonShownWhenRetainingAndFailedExist(): void
    {
        $event = $this->makeEvent(retainOriginals: true);
        $this->addFailed($event, 'aa', 'boom');

        $this->client->request(
            Request::METHOD_GET,
            sprintf('/admin/events/%d/photos-grid', (int) $event->getId()),
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action$="/photos/retry-all"]');
    }

    public function testRetryAllButtonHiddenWhenNoFailedPhotos(): void
    {
        $event = $this->makeEvent(retainOriginals: true);
        $this->addReady($event, 'aa');

        $this->client->request(
            Request::METHOD_GET,
            sprintf('/admin/events/%d/photos-grid', (int) $event->getId()),
        );

        self::assertResponseIsSuccessful();
        $this->assertStringNotContainsString('/retry-all', (string) $this->client->getResponse()->getContent());
    }

    public function testRetryButtonShownForFailedPhotoWhenRetaining(): void
    {
        $event = $this->makeEvent(retainOriginals: true);
        $this->addFailed($event, 'aa', 'boom');

        $this->client->request(
            Request::METHOD_GET,
            sprintf('/admin/events/%d/photos-grid', (int) $event->getId()),
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('tbody form[action$="/retry"]');
    }

    public function testRetryButtonHiddenForFailedPhotoWhenNotRetaining(): void
    {
        $event = $this->makeEvent(retainOriginals: false);
        $this->addFailed($event, 'aa', 'boom');

        $this->client->request(
            Request::METHOD_GET,
            sprintf('/admin/events/%d/photos-grid', (int) $event->getId()),
        );

        self::assertResponseIsSuccessful();
        $this->assertStringNotContainsString('/retry', (string) $this->client->getResponse()->getContent());
    }
}
