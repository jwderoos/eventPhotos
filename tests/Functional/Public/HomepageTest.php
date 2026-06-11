<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class HomepageTest extends WebTestCase
{
    public function testHomepageShowsAppName(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $user = new User('homepage-test@example.com', 'HomepageTest');
        $user->setPassword('x');

        $em->persist($user);
        $em->flush();

        $client->request(Request::METHOD_GET, '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Event Photos');
    }
}
