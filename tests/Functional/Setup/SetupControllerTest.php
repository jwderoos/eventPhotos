<?php

declare(strict_types=1);

namespace App\Tests\Functional\Setup;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SetupControllerTest extends WebTestCase
{
    public function testGetSetupRendersFormWhenEmpty(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/setup');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="setup_form[email]"]');
    }

    public function testPostSetupCreatesFirstAdminAndLogsThemIn(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);

        $this->assertSame(0, $users->count([]));

        $client->request(Request::METHOD_GET, '/setup');
        $client->submitForm('Create admin account', [
            'setup_form[email]'                    => 'first.admin@example.com',
            'setup_form[displayName]'              => 'First Admin',
            'setup_form[plainPassword][first]'     => 'a strong password 1',
            'setup_form[plainPassword][second]'    => 'a strong password 1',
        ]);

        self::assertResponseRedirects('/admin');

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $em->clear();

        $created = $users->findOneByEmail('first.admin@example.com');
        $this->assertInstanceOf(User::class, $created);
        $this->assertContains('ROLE_ADMIN', $created->getRoles());

        // Verify the redirect lands somewhere authenticated (auto-login worked).
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testSetupIs404OnceAUserExists(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $existing = new User('existing@example.com', 'Existing');
        $existing->addRole('ROLE_ADMIN');
        $existing->setPassword($hasher->hashPassword($existing, 'whatever'));

        $em->persist($existing);
        $em->flush();

        $client->request(Request::METHOD_GET, '/setup');
        self::assertResponseStatusCodeSame(404);
    }
}
