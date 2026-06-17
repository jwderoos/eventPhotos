<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use Symfony\Component\HttpFoundation\Request;
use App\Entity\User;
use App\Entity\UserSession;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SessionControllerRevokeTest extends WebTestCase
{
    public function testRevokeDeletesUsersOwnSessionRow(): void
    {
        $client = self::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $em);
        $user = $this->makeUser($em);
        $client->loginUser($user);

        $sessId = 'revoke_own_' . bin2hex(random_bytes(8));

        // Insert into both sessions (framework) and user_sessions to exercise the trigger.
        $em->getConnection()->executeStatement(
            'INSERT INTO sessions (sess_id, sess_data, sess_time, sess_lifetime) VALUES (?, ?, ?, ?)',
            [$sessId, '', time(), 2592000],
        );
        $em->persist(new UserSession($sessId, $user, '8.8.8.8', 'ua', null, null, new DateTimeImmutable()));
        $em->flush();

        $crawler = $client->request(Request::METHOD_GET, '/account/sessions');
        $token = $crawler->filter('form[action$="/revoke"] input[name=_token]')->first()->attr('value');

        $client->request(Request::METHOD_POST, '/account/sessions/' . $sessId . '/revoke', ['_token' => $token]);

        self::assertResponseRedirects('/account/sessions');

        // Row gone from user_sessions (trigger cascaded).
        $row = $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM user_sessions WHERE sess_id = ?',
            [$sessId],
        );
        $this->assertIsScalar($row);
        $this->assertSame(0, (int) $row);

        // Row gone from sessions too.
        $sessRow = $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM sessions WHERE sess_id = ?',
            [$sessId],
        );
        $this->assertIsScalar($sessRow);
        $this->assertSame(0, (int) $sessRow);
    }

    public function testRevokeRejectsMissingCsrf(): void
    {
        $client = self::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $em);
        $user = $this->makeUser($em);
        $client->loginUser($user);

        $sessId = 'no_csrf_' . bin2hex(random_bytes(8));
        $em->persist(new UserSession($sessId, $user, '8.8.8.8', 'ua', null, null, new DateTimeImmutable()));
        $em->flush();

        $client->request(Request::METHOD_POST, '/account/sessions/' . $sessId . '/revoke');
        self::assertResponseStatusCodeSame(403);
    }

    public function testRevokeDeniesOtherUsersSession(): void
    {
        $client = self::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $em);
        $me = $this->makeUser($em);
        $other = $this->makeUser($em);
        $client->loginUser($me);

        $sessId = 'other_user_' . bin2hex(random_bytes(8));
        $em->persist(new UserSession($sessId, $other, '8.8.8.8', 'ua', null, null, new DateTimeImmutable()));
        $em->flush();

        $crawler = $client->request(Request::METHOD_GET, '/account/sessions');
        $token = $crawler->filter('input[name=_token]')->first()->attr('value');

        $client->request(Request::METHOD_POST, '/account/sessions/' . $sessId . '/revoke', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
    }

    private function makeUser(EntityManagerInterface $em): User
    {
        $user = new User(
            'revoke-test-' . bin2hex(random_bytes(4)) . '@example.com',
            'Revoke Test',
        );
        $user->setPassword('x');
        $user->addRole('ROLE_ORGANIZER');

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
