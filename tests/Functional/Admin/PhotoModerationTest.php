<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\PhotoStatus;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;
use Throwable;

final class PhotoModerationTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private FilesystemOperator $originals;

    private FilesystemOperator $thumbs;

    private FilesystemOperator $previews;

    private Event $event;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $c = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var FilesystemOperator $originals */
        $originals = $c->get('photo_originals_storage');
        /** @var FilesystemOperator $thumbs */
        $thumbs = $c->get('photo_thumbs_storage');
        /** @var FilesystemOperator $previews */
        $previews = $c->get('photo_previews_storage');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);

        $this->em        = $em;
        $this->originals = $originals;
        $this->thumbs    = $thumbs;
        $this->previews  = $previews;

        $owner = new User('o@example.test', 'O');
        $owner->setPassword($hasher->hashPassword($owner, 'x'));
        $owner->addRole('ROLE_ORGANIZER');

        $this->em->persist($owner);

        $this->event = new Event(
            'e',
            'E',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $this->event->setTimezone('UTC');

        $this->em->persist($this->event);
        $this->em->flush();

        $this->client->loginUser($owner);
    }

    public function testRetryRouteIsGone(): void
    {
        $photo = new Photo($this->event, str_repeat('a', 64), 'x.jpg', 100);
        $photo->markFailed('boom');

        $this->em->persist($photo);
        $this->em->flush();

        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/%d/retry', (int) $this->event->getId(), (int) $photo->getId()),
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteRemovesRowAndStorageFiles(): void
    {
        $photo = new Photo($this->event, str_repeat('b', 64), 'x.jpg', 100);
        $photo->markReady(new DateTimeImmutable('now', new DateTimeZone('UTC')), 100, 100);

        $this->em->persist($photo);
        $this->em->flush();

        $path = sprintf('event-%d/%d.jpg', (int) $this->event->getId(), (int) $photo->getId());
        $this->originals->write($path, 'a');
        $this->thumbs->write($path, 'b');
        $this->previews->write($path, 'c');

        $photoId = (int) $photo->getId();
        $token   = $this->primeCsrfToken('delete_photo_' . $photoId);

        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/%d/delete', (int) $this->event->getId(), $photoId),
            ['_token' => $token],
        );

        self::assertResponseRedirects(sprintf('/admin/events/%d/photos-grid?page=1', (int) $this->event->getId()));
        $this->assertNotInstanceOf(Photo::class, $this->em->find(Photo::class, $photoId));
        $this->assertFalse($this->originals->fileExists($path));
        $this->assertFalse($this->thumbs->fileExists($path));
        $this->assertFalse($this->previews->fileExists($path));
    }

    public function testDeleteAllRemovesEveryPhotoAndItsFiles(): void
    {
        $eventId = (int) $this->event->getId();

        $ready = new Photo($this->event, str_repeat('d', 64), 'a.jpg', 100);
        $ready->markReady(new DateTimeImmutable('now', new DateTimeZone('UTC')), 100, 100);

        $pending = new Photo($this->event, str_repeat('e', 64), 'b.jpg', 100);

        $this->em->persist($ready);
        $this->em->persist($pending);
        $this->em->flush();

        $readyPath   = sprintf('event-%d/%d.jpg', $eventId, (int) $ready->getId());
        $pendingPath = sprintf('event-%d/%d.jpg', $eventId, (int) $pending->getId());
        $this->originals->write($readyPath, 'a');
        $this->thumbs->write($readyPath, 'b');
        $this->previews->write($readyPath, 'c');
        $this->originals->write($pendingPath, 'd');

        $token = $this->primeCsrfToken('delete_all_photos_' . $eventId);

        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/delete-all', $eventId),
            ['_token' => $token],
        );

        self::assertResponseRedirects(sprintf('/admin/events/%d/photos-grid', $eventId));
        $this->em->clear();
        $this->assertNotInstanceOf(Photo::class, $this->em->find(Photo::class, (int) $ready->getId()));
        $this->assertNotInstanceOf(Photo::class, $this->em->find(Photo::class, (int) $pending->getId()));
        $this->assertFalse($this->originals->fileExists($readyPath));
        $this->assertFalse($this->thumbs->fileExists($readyPath));
        $this->assertFalse($this->previews->fileExists($readyPath));
        $this->assertFalse($this->originals->fileExists($pendingPath));
    }

    public function testDeleteAllRejectsMissingCsrf(): void
    {
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/delete-all', (int) $this->event->getId()),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteRejectsMissingCsrf(): void
    {
        $photo = new Photo($this->event, str_repeat('c', 64), 'x.jpg', 100);

        $this->em->persist($photo);
        $this->em->flush();

        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/%d/delete', (int) $this->event->getId(), (int) $photo->getId()),
        );

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Issues a benign GET so the test client has an active session, then writes
     * a known CSRF token into that session under the fallback session-token namespace
     * (the same one `isCsrfTokenValid()` consults for non-stateless token ids).
     *
     * Returns the token value to send back as `_token`.
     */
    private function primeCsrfToken(string $tokenId): string
    {
        // Boot a session for the client.
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

    protected function tearDown(): void
    {
        try {
            $eventId = $this->event->getId();
            if ($eventId !== null) {
                $dir = sprintf('event-%d', $eventId);
                $this->originals->deleteDirectory($dir);
                $this->thumbs->deleteDirectory($dir);
                $this->previews->deleteDirectory($dir);
            }
        } catch (Throwable) {
        }

        parent::tearDown();
    }
}
