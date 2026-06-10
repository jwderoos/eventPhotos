<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EventQrTest extends WebTestCase
{
    public function testOwnerSeesPrintPageWithEventNameAndSvg(): void
    {
        $client = self::createClient();
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $alice = new User('alice@example.com', 'Alice');
        $alice->addRole('ROLE_ORGANIZER');
        $alice->setPassword($hasher->hashPassword($alice, 'pw'));

        $event = new Event('summer-fest', 'Summer Fest', new DateTimeImmutable('2026-07-15'), $alice);

        $em->persist($alice);
        $em->persist($event);
        $em->flush();

        $client->loginUser($alice);
        $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/qr', (int) $event->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Summer Fest');
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('<svg', $content);
        $this->assertStringContainsString('Scan to see your photos', $content);
    }

    public function testOwnerDownloadsPngWithCorrectContentType(): void
    {
        $client = self::createClient();
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $alice = new User('alice@example.com', 'Alice');
        $alice->addRole('ROLE_ORGANIZER');
        $alice->setPassword($hasher->hashPassword($alice, 'pw'));

        $event = new Event('summer-fest', 'Summer Fest', new DateTimeImmutable('2026-07-15'), $alice);

        $em->persist($alice);
        $em->persist($event);
        $em->flush();

        $client->loginUser($alice);
        $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/qr.png', (int) $event->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'image/png');
        $this->assertResponseHeaderSame('Content-Disposition', 'attachment; filename="event-summer-fest.png"');
        $body = (string) $client->getResponse()->getContent();
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $body);
    }

    public function testNonOwnerOrganizerGets403(): void
    {
        $client = self::createClient();
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $alice = new User('alice@example.com', 'Alice');
        $alice->addRole('ROLE_ORGANIZER');
        $alice->setPassword($hasher->hashPassword($alice, 'pw'));

        $bob = new User('bob@example.com', 'Bob');
        $bob->addRole('ROLE_ORGANIZER');
        $bob->setPassword($hasher->hashPassword($bob, 'pw'));

        $aliceEvent = new Event('alice-fest', 'Alice Fest', new DateTimeImmutable('2026-07-15'), $alice);

        $em->persist($alice);
        $em->persist($bob);
        $em->persist($aliceEvent);
        $em->flush();

        $client->loginUser($bob);
        $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/qr', (int) $aliceEvent->getId()));

        $this->assertResponseStatusCodeSame(403);
    }
}
