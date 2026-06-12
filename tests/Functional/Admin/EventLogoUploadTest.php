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

final class EventLogoUploadTest extends WebTestCase
{
    public function testOwnerUploadsValidPngLogo(): void
    {
        $client = self::createClient();
        $client->disableReboot();

        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var FilesystemOperator $storage */
        $storage = $container->get('event_logos_storage');

        $alice = new User('alice@example.com', 'Alice');
        $alice->addRole('ROLE_ORGANIZER');
        $alice->setPassword($hasher->hashPassword($alice, 'pw'));

        $event = new Event(
            'summer-fest',
            'Summer Fest',
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
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form();
        /** @phpstan-ignore-next-line method.nonObject */
        $form['event[logoFile][file]']->upload(__DIR__ . '/../../fixtures/logo.png');
        $client->submit($form);

        $this->assertResponseRedirects('/admin/events');

        $em->clear();
        $reloaded = $em->find(Event::class, $eventId);
        $this->assertInstanceOf(Event::class, $reloaded);
        $this->assertNotNull($reloaded->getLogoFilename());
        $this->assertTrue($storage->fileExists($reloaded->getLogoFilename()));
    }

    public function testSvgUploadIsRejected(): void
    {
        $client = self::createClient();
        $client->disableReboot();

        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $alice = new User('alice@example.com', 'Alice');
        $alice->addRole('ROLE_ORGANIZER');
        $alice->setPassword($hasher->hashPassword($alice, 'pw'));

        $event = new Event(
            'summer-fest',
            'Summer Fest',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $alice,
        );

        $em->persist($alice);
        $em->persist($event);
        $em->flush();

        $svgPath = sys_get_temp_dir() . '/logo-test.svg';
        file_put_contents($svgPath, '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', (int) $event->getId()));

        $form = $crawler->selectButton('Save')->form();
        /** @phpstan-ignore-next-line method.nonObject */
        $form['event[logoFile][file]']->upload($svgPath);
        $client->submit($form);

        @unlink($svgPath);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString(
            'Please upload a PNG or JPEG image.',
            (string) $client->getResponse()->getContent(),
        );

        $em->clear();
        $reloaded = $em->find(Event::class, (int) $event->getId());
        $this->assertInstanceOf(Event::class, $reloaded);
        $this->assertNull($reloaded->getLogoFilename());
    }

    public function testOversizeUploadIsRejected(): void
    {
        $client = self::createClient();
        $client->disableReboot();

        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $alice = new User('alice@example.com', 'Alice');
        $alice->addRole('ROLE_ORGANIZER');
        $alice->setPassword($hasher->hashPassword($alice, 'pw'));

        $event = new Event(
            'summer-fest',
            'Summer Fest',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $alice,
        );

        $em->persist($alice);
        $em->persist($event);
        $em->flush();

        $bigPath = sys_get_temp_dir() . '/big.png';
        $header = "\x89PNG\r\n\x1a\n";
        file_put_contents($bigPath, $header . str_repeat('A', 2_100_000));

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', (int) $event->getId()));

        $form = $crawler->selectButton('Save')->form();
        /** @phpstan-ignore-next-line method.nonObject */
        $form['event[logoFile][file]']->upload($bigPath);
        $client->submit($form);

        @unlink($bigPath);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('too large', (string) $client->getResponse()->getContent());

        $em->clear();
        $reloaded = $em->find(Event::class, (int) $event->getId());
        $this->assertInstanceOf(Event::class, $reloaded);
        $this->assertNull($reloaded->getLogoFilename());
    }

    public function testOwnerDeletesExistingLogoViaCheckbox(): void
    {
        $client = self::createClient();
        $client->disableReboot();

        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var FilesystemOperator $storage */
        $storage = $container->get('event_logos_storage');

        $alice = new User('alice@example.com', 'Alice');
        $alice->addRole('ROLE_ORGANIZER');
        $alice->setPassword($hasher->hashPassword($alice, 'pw'));

        $event = new Event(
            'summer-fest',
            'Summer Fest',
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
        $form = $crawler->selectButton('Save')->form();
        /** @phpstan-ignore-next-line method.nonObject */
        $form['event[logoFile][file]']->upload(__DIR__ . '/../../fixtures/logo.png');
        $client->submit($form);
        $this->assertResponseRedirects('/admin/events');

        $em->clear();
        $reloaded = $em->find(Event::class, $eventId);
        $this->assertInstanceOf(Event::class, $reloaded);
        $this->assertNotNull($reloaded->getLogoFilename());
        $storedName = $reloaded->getLogoFilename();

        $crawler = $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', $eventId));
        $form = $crawler->selectButton('Save')->form();
        /** @phpstan-ignore-next-line method.nonObject */
        $form['event[logoFile][delete]']->tick();
        $client->submit($form);
        $this->assertResponseRedirects('/admin/events');

        $em->clear();
        $afterDelete = $em->find(Event::class, $eventId);
        $this->assertInstanceOf(Event::class, $afterDelete);
        $this->assertNull($afterDelete->getLogoFilename());
        $this->assertFalse($storage->fileExists($storedName));
    }
}
