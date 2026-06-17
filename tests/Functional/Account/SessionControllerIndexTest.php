<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use Symfony\Component\HttpFoundation\Request;
use App\Entity\User;
use App\Entity\UserSession;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SessionControllerIndexTest extends WebTestCase
{
    public function testListsCurrentUsersSessions(): void
    {
        $client = self::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $em);

        $user = $this->makeUser($em);
        $client->loginUser($user);

        $now = new DateTimeImmutable();
        $a = new UserSession(
            'sess_a_' . bin2hex(random_bytes(4)),
            $user,
            '8.8.8.8',
            'UA-A',
            'Chrome — macOS',
            'US',
            $now,
        );
        $b = new UserSession(
            'sess_b_' . bin2hex(random_bytes(4)),
            $user,
            '1.1.1.1',
            'UA-B',
            'Firefox — Linux',
            null,
            $now,
        );
        $em->persist($a);
        $em->persist($b);
        $em->flush();

        $client->request(Request::METHOD_GET, '/account/sessions');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1, h2', 'Sessions');
        self::assertSelectorTextContains('body', '8.8.8.8');
        self::assertSelectorTextContains('body', '1.1.1.1');
        self::assertSelectorTextContains('body', 'Chrome');
    }

    public function testDoesNotListOtherUsersSessions(): void
    {
        $client = self::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $em);

        $me = $this->makeUser($em);
        $other = $this->makeUser($em);
        $client->loginUser($me);

        $now = new DateTimeImmutable();
        $otherSession = new UserSession(
            'sess_other_' . bin2hex(random_bytes(4)),
            $other,
            '9.9.9.9',
            'UA-OTHER',
            null,
            null,
            $now,
        );
        $em->persist($otherSession);
        $em->flush();

        $client->request(Request::METHOD_GET, '/account/sessions');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', '9.9.9.9');
    }

    public function testRedirectsAnonymousUserToLogin(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/account/sessions');
        self::assertResponseRedirects();
    }

    private function makeUser(EntityManagerInterface $em): User
    {
        $user = new User(
            'sessions-test-' . bin2hex(random_bytes(4)) . '@example.com',
            'Sessions Test',
        );
        $user->setPassword('x');
        $user->addRole('ROLE_ORGANIZER');

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
