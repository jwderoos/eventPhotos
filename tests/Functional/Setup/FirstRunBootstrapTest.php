<?php

declare(strict_types=1);

namespace App\Tests\Functional\Setup;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class FirstRunBootstrapTest extends WebTestCase
{
    public function testZeroUsersRedirectsLoginToSetup(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/login');
        self::assertResponseRedirects('/setup');
    }

    public function testZeroUsersRedirectsAdminToSetup(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/admin');
        self::assertResponseRedirects('/setup');
    }

    public function testZeroUsersRedirectsRootToSetup(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/');
        self::assertResponseRedirects('/setup');
    }

    public function testSetupItselfIsNotRedirected(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/setup');
        // 404 is fine here (SetupController not built yet); the assertion is "no redirect".
        $this->assertFalse(
            $client->getResponse()->isRedirect('/setup'),
            'GET /setup must never redirect to itself',
        );
    }

    public function testAfterFirstUserLoginIsNoLongerRedirected(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $admin = new User('admin@example.com', 'Admin');
        $admin->addRole('ROLE_ADMIN');
        $admin->setPassword($hasher->hashPassword($admin, 'irrelevant for test'));

        $em->persist($admin);
        $em->flush();

        $client->request(Request::METHOD_GET, '/login');
        self::assertResponseIsSuccessful();
    }
}
