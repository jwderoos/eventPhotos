<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Entity\UserSession;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserSessionControllerTest extends WebTestCase
{
    public function testAdminListsAnotherUsersSessions(): void
    {
        $client = self::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $em);

        $admin = $this->makeUser($em, ['ROLE_ADMIN']);
        $target = $this->makeUser($em, ['ROLE_ORGANIZER']);

        $em->persist(
            new UserSession(
                'admin_view_' . bin2hex(random_bytes(4)),
                $target,
                '5.5.5.5',
                'ua-target',
                'Chrome — Linux',
                'NL',
                new DateTimeImmutable(),
            )
        );
        $em->flush();

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/users/' . $target->getId() . '/sessions');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', $target->getEmail());
        $this->assertSelectorTextContains('body', '5.5.5.5');
    }

    public function testNonAdminGetsForbidden(): void
    {
        $client = self::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $em);

        $organizer = $this->makeUser($em, ['ROLE_ORGANIZER']);
        $target = $this->makeUser($em, ['ROLE_ORGANIZER']);

        $client->loginUser($organizer);
        $client->request(Request::METHOD_GET, '/admin/users/' . $target->getId() . '/sessions');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminRevokesAnotherUsersSession(): void
    {
        $client = self::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $em);

        $admin = $this->makeUser($em, ['ROLE_ADMIN']);
        $target = $this->makeUser($em, ['ROLE_ORGANIZER']);

        $sessId = 'admin_revoke_' . bin2hex(random_bytes(8));
        $em->getConnection()->executeStatement(
            'INSERT INTO sessions (sess_id, sess_data, sess_time, sess_lifetime) VALUES (?, ?, ?, ?)',
            [$sessId, '', time(), 2592000],
        );
        $em->persist(new UserSession($sessId, $target, '5.5.5.5', 'ua', null, null, new DateTimeImmutable()));
        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/users/' . $target->getId() . '/sessions');
        $token = $crawler->filter('input[name=_token]')->first()->attr('value');

        $client->request(
            Request::METHOD_POST,
            '/admin/users/' . $target->getId() . '/sessions/' . $sessId . '/revoke',
            ['_token' => $token]
        );
        $this->assertResponseRedirects();

        $count = $em->getConnection()->fetchOne('SELECT COUNT(*) FROM user_sessions WHERE sess_id = ?', [$sessId]);
        $this->assertIsScalar($count);
        $this->assertSame(0, (int)$count);
    }

    /** @param list<string> $roles */
    private function makeUser(EntityManagerInterface $em, array $roles): User
    {
        $container = self::getContainer();
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $this->assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = new User(
            'user-' . bin2hex(random_bytes(4)) . '@example.com',
            'Test User',
        );
        foreach ($roles as $role) {
            $user->addRole($role);
        }

        $user->setPassword($hasher->hashPassword($user, 'placeholder placeholder'));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
