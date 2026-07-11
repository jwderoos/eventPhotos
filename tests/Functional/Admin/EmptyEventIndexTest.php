<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EmptyEventIndexTest extends WebTestCase
{
    public function testOrganizerWithNoEventsSeesEmptyState(): void
    {
        $client = self::createClient();
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $organizer = new User('lonely@example.com', 'Lonely');
        $organizer->addRole('ROLE_ORGANIZER');
        $organizer->setPassword($hasher->hashPassword($organizer, 'pw'));

        $em->persist($organizer);
        $em->flush();

        $client->loginUser($organizer);
        $client->request(Request::METHOD_GET, '/admin/events');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('No events yet.', (string) $client->getResponse()->getContent());
    }
}
