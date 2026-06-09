<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use Symfony\Component\HttpFoundation\Request;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LoginTest extends WebTestCase
{
    public function testValidCredentialsLogInAndRedirectToAdmin(): void
    {
        $kernelBrowser = self::createClient();
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User('alice@example.com', 'Alice');
        $user->addRole('ROLE_ORGANIZER');
        $user->setPassword($hasher->hashPassword($user, 'correct horse battery'));

        $em->persist($user);
        $em->flush();

        $kernelBrowser->request(Request::METHOD_GET, '/login');
        $kernelBrowser->submitForm('Sign in', [
            '_username' => 'alice@example.com',
            '_password' => 'correct horse battery',
        ]);

        self::assertResponseRedirects('/admin');
    }

    public function testInvalidCredentialsShowError(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->request(Request::METHOD_GET, '/login');
        $kernelBrowser->submitForm('Sign in', [
            '_username' => 'nobody@example.com',
            '_password' => 'nope',
        ]);

        $kernelBrowser->followRedirect();
        self::assertSelectorTextContains('.error', 'Invalid credentials');
    }
}
