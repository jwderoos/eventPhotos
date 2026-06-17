<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use Symfony\Component\HttpFoundation\Request;
use App\Entity\User;
use App\Entity\UserSession;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SessionControllerRevokeOthersTest extends WebTestCase
{
    public function testRevokesAllOtherSessionsKeepingCurrent(): void
    {
        $client = self::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $em);
        $user = $this->makeUser($em);
        $other = $this->makeUser($em);
        $client->loginUser($user);

        // Get the current sess_id by visiting any page first.
        $client->request(Request::METHOD_GET, '/account/sessions');

        $currentSessId = $client->getRequest()->getSession()->getId();

        // Seed two other sessions for the same user + one for another user.
        // The current session row in user_sessions was already created by the request listener
        // during the GET above — do NOT re-persist it to avoid EntityIdentityCollisionException.
        $conn = $em->getConnection();
        $sess = function (string $id) use ($conn): void {
            $conn->executeStatement(
                'INSERT INTO sessions (sess_id, sess_data, sess_time, sess_lifetime) VALUES (?, ?, ?, ?)',
                [$id, '', time(), 2592000],
            );
        };

        $otherSessId1 = 'rev_oth_1_' . bin2hex(random_bytes(4));
        $sess($otherSessId1);
        $em->persist(new UserSession($otherSessId1, $user, '2.2.2.2', 'ua', null, null, new DateTimeImmutable()));

        $otherSessId2 = 'rev_oth_2_' . bin2hex(random_bytes(4));
        $sess($otherSessId2);
        $em->persist(new UserSession($otherSessId2, $user, '3.3.3.3', 'ua', null, null, new DateTimeImmutable()));

        $otherUserSessId = 'rev_oth_x_' . bin2hex(random_bytes(4));
        $sess($otherUserSessId);
        $em->persist(new UserSession($otherUserSessId, $other, '4.4.4.4', 'ua', null, null, new DateTimeImmutable()));

        $em->flush();

        $crawler = $client->request(Request::METHOD_GET, '/account/sessions');
        // Use ends-with selector for the revoke-others form action specifically.
        $token = $crawler->filter('form[action$="/account/sessions/revoke-others"] input[name=_token]')->attr('value');

        $client->request(Request::METHOD_POST, '/account/sessions/revoke-others', ['_token' => $token]);
        self::assertResponseRedirects('/account/sessions');

        $countSql = 'SELECT COUNT(*) FROM user_sessions WHERE sess_id = ?';
        // Current session row still present.
        $this->assertCountSql(1, $conn->fetchOne($countSql, [$currentSessId]));
        // Other rows for the same user gone (trigger cascaded).
        $this->assertCountSql(0, $conn->fetchOne($countSql, [$otherSessId1]));
        $this->assertCountSql(0, $conn->fetchOne($countSql, [$otherSessId2]));
        // Other user's row untouched.
        $this->assertCountSql(1, $conn->fetchOne($countSql, [$otherUserSessId]));
    }

    private function makeUser(EntityManagerInterface $em): User
    {
        $user = new User(
            'revoke-others-' . bin2hex(random_bytes(4)) . '@example.com',
            'Revoke Others Test',
        );
        $user->setPassword('x');
        $user->addRole('ROLE_ORGANIZER');

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function assertCountSql(int $expected, mixed $actual): void
    {
        $this->assertIsScalar($actual);
        $this->assertSame($expected, (int) $actual);
    }
}
