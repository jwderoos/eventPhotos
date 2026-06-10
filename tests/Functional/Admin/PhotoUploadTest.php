<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use Symfony\Component\HttpFoundation\Request;
use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\PhotoStatus;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Throwable;

final class PhotoUploadTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private FilesystemOperator $originals;

    private Event $event;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $c = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var FilesystemOperator $originals */
        $originals = $c->get('photo_originals_storage');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);

        $this->em = $em;
        $this->originals = $originals;

        $owner = new User('owner@example.test', 'Owner');
        $owner->setPassword($hasher->hashPassword($owner, 'secret'));
        $owner->addRole('ROLE_ORGANIZER');

        $this->em->persist($owner);

        $this->event = new Event('demo', 'Demo', new DateTimeImmutable('2026-06-10'), $owner);
        $this->event->setTimezone('Europe/Amsterdam');

        $this->em->persist($this->event);
        $this->em->flush();

        $this->client->loginUser($owner);
    }

    public function testHappyPathReturnsPendingAndPersistsRow(): void
    {
        $file = $this->fixture('with-datetime-original.jpg');
        $url  = sprintf('/admin/events/%d/photos', (int) $this->event->getId());

        $this->client->request(Request::METHOD_POST, $url, [], ['file' => $file]);

        self::assertResponseStatusCodeSame(202);
        $body = (array) json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('pending', $body['status'] ?? null);
        $this->assertIsInt($body['photoId'] ?? null);

        $photo = $this->em->find(Photo::class, $body['photoId']);
        $this->assertInstanceOf(Photo::class, $photo);
        $this->assertSame(PhotoStatus::Pending, $photo->getStatus());
        $this->assertTrue($this->originals->fileExists(
            sprintf('event-%d/%d.jpg', (int) $this->event->getId(), (int) $photo->getId()),
        ));
    }

    public function testDuplicateUploadReturnsDuplicateAndDoesNotInsertNewRow(): void
    {
        $url = sprintf('/admin/events/%d/photos', (int) $this->event->getId());

        $file1 = $this->fixture('with-datetime-original.jpg');
        $this->client->request(Request::METHOD_POST, $url, [], ['file' => $file1]);
        $firstBody = (array) json_decode((string) $this->client->getResponse()->getContent(), true);
        $rawId     = $firstBody['photoId'] ?? null;
        $this->assertIsInt($rawId);
        $firstId   = $rawId;

        $file2 = $this->fixture('with-datetime-original.jpg');
        $this->client->request(Request::METHOD_POST, $url, [], ['file' => $file2]);

        self::assertResponseStatusCodeSame(200);
        $body = (array) json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('duplicate', $body['status']);
        $this->assertSame($firstId, $body['photoId']);
    }

    public function testRejectsNonJpeg(): void
    {
        $tmp = sys_get_temp_dir() . '/text-' . uniqid() . '.txt';
        file_put_contents($tmp, 'not an image');
        $file = new UploadedFile($tmp, 'fake.txt', 'text/plain', null, true);
        $url  = sprintf('/admin/events/%d/photos', (int) $this->event->getId());

        $this->client->request(Request::METHOD_POST, $url, [], ['file' => $file]);

        self::assertResponseStatusCodeSame(415);
    }

    public function testRejectsForNonOwner(): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher   = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stranger = new User('stranger@example.test', 'Stranger');
        $stranger->setPassword($hasher->hashPassword($stranger, 'x'));
        $stranger->addRole('ROLE_ORGANIZER');

        $this->em->persist($stranger);
        $this->em->flush();

        $this->client->loginUser($stranger);

        $file = $this->fixture('with-datetime-original.jpg');
        $url  = sprintf('/admin/events/%d/photos', (int) $this->event->getId());
        $this->client->request(Request::METHOD_POST, $url, [], ['file' => $file]);

        self::assertResponseStatusCodeSame(403);
    }

    private function fixture(string $name): UploadedFile
    {
        $src = dirname(__DIR__, 2) . '/fixtures/photos/' . $name;
        $dst = sys_get_temp_dir() . '/upload-' . uniqid() . '-' . $name;
        copy($src, $dst);

        return new UploadedFile($dst, $name, 'image/jpeg', null, true);
    }

    protected function tearDown(): void
    {
        try {
            $this->originals->deleteDirectory(sprintf('event-%d', $this->event->getId() ?? 0));
        } catch (Throwable) {
        }

        parent::tearDown();
    }
}
