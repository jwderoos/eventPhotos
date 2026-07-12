<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EventBannerUploadTest extends WebTestCase
{
    public function testOwnerUploadsBannerThenRemovesIt(): void
    {
        $client = self::createClient();
        $client->disableReboot();

        $c = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);
        /** @var FilesystemOperator $storage */
        $storage = $c->get('event_banners_storage');

        $alice = new User('banner-alice@example.com', 'Alice');
        $alice->addRole('ROLE_ORGANIZER');
        $alice->setPassword($hasher->hashPassword($alice, 'pw'));

        $event = new Event(
            'banner-fest',
            'Banner Fest',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $alice,
        );

        $em->persist($alice);
        $em->persist($event);
        $em->flush();

        $eventId = (int) $event->getId();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', $eventId));
        self::assertResponseIsSuccessful();

        // Upload a banner.
        $form = $crawler->selectButton('Save')->form();
        /** @phpstan-ignore-next-line method.nonObject */
        $form['event[bannerFile]']->upload(dirname(__DIR__, 2) . '/fixtures/photos/bigger.jpg');
        $client->submit($form);
        self::assertResponseRedirects('/admin/events');

        $em->clear();
        $reloaded = $em->find(Event::class, $eventId);
        $this->assertInstanceOf(Event::class, $reloaded);
        $filename = $reloaded->getBannerFilename();
        $this->assertNotNull($filename);
        $this->assertTrue($storage->fileExists($filename));

        // Now remove it.
        $crawler = $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', $eventId));
        $form = $crawler->selectButton('Save')->form();
        /** @phpstan-ignore-next-line method.nonObject */
        $form['event[removeBanner]']->tick();
        $client->submit($form);
        self::assertResponseRedirects('/admin/events');

        $em->clear();
        $reloaded = $em->find(Event::class, $eventId);
        $this->assertInstanceOf(Event::class, $reloaded);
        $this->assertNull($reloaded->getBannerFilename());
    }
}
