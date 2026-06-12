<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use Symfony\Component\HttpFoundation\Request;
use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class OwnershipScopingTest extends WebTestCase
{
    public function testOrganizerOnlySeesOwnEventsInTheAdminIndex(): void
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

        $em->persist($alice);
        $em->persist($bob);

        $em->persist(new Event(
            'alice-event',
            'Alice Event',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $alice,
        ));
        $em->persist(new Event(
            'bob-event',
            'Bob Event',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $bob,
        ));
        $em->flush();

        $client->loginUser($alice);
        $client->request(Request::METHOD_GET, '/admin/events');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Alice Event', $content);
        $this->assertStringNotContainsString('Bob Event', $content);
    }

    public function testOrganizerCannotEditOthersEvent(): void
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

        $bobEvent = new Event(
            'bob-event',
            'Bob Event',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $bob,
        );
        $em->persist($alice);
        $em->persist($bob);
        $em->persist($bobEvent);
        $em->flush();

        $client->loginUser($alice);
        $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', (int) $bobEvent->getId()));

        $this->assertResponseStatusCodeSame(403);
    }
}
