<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\User;
use App\Repository\EventRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

final class EventDeleteTest extends WebTestCase
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

        $owner = new User('del-owner@example.test', 'Owner');
        $owner->setPassword($hasher->hashPassword($owner, 'secret'));
        $owner->addRole('ROLE_ORGANIZER');

        $this->em->persist($owner);

        $this->event = new Event(
            'delete-demo',
            'Delete Demo',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $this->event->setRetainOriginals(true);

        $this->em->persist($this->event);
        $this->em->flush();

        $this->client->loginUser($owner);
    }

    public function testDeletingEventRemovesAllStorageDirectories(): void
    {
        $photo = new Photo($this->event, str_pad('a', 64, '0'), 'a.jpg', 100);
        $this->em->persist($photo);
        $this->em->flush();

        $eventId = (int) $this->event->getId();
        $path    = sprintf('event-%d/%d.jpg', $eventId, (int) $photo->getId());
        $this->originals->write($path, "\xFF\xD8ORIGINAL");
        $this->thumbs->write($path, "\xFF\xD8THUMB");
        $this->previews->write($path, "\xFF\xD8PREVIEW");

        $token = $this->primeCsrfToken('delete_event_' . $eventId);
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/delete', $eventId),
            ['_token' => $token],
        );

        self::assertResponseRedirects('/admin/events');

        /** @var EventRepository $events */
        $events = self::getContainer()->get(EventRepository::class);
        $this->assertNotInstanceOf(Event::class, $events->find($eventId), 'Event row must be gone.');

        $dir = sprintf('event-%d', $eventId);
        $this->assertFalse($this->originals->directoryExists($dir), 'Originals dir must be removed.');
        $this->assertFalse($this->thumbs->directoryExists($dir), 'Thumbs dir must be removed.');
        $this->assertFalse($this->previews->directoryExists($dir), 'Previews dir must be removed.');
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
}
