<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Entity\UserSession;
use App\Security\Voter\UserSessionVoter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class UserSessionVoterTest extends TestCase
{
    public function testGrantsManageOnOwnSession(): void
    {
        $user    = $this->makeUser(1, 'user@example.com', 'ROLE_ORGANIZER');
        $session = $this->makeSession($user);
        $token   = new UsernamePasswordToken($user, 'main', $user->getRoles());

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            new UserSessionVoter()->vote($token, $session, [UserSessionVoter::MANAGE]),
        );
    }

    public function testDeniesManageOnOtherUsersSession(): void
    {
        $owner = $this->makeUser(1, 'owner@example.com', 'ROLE_ORGANIZER');
        $other = $this->makeUser(2, 'other@example.com', 'ROLE_ORGANIZER');

        $session = $this->makeSession($owner);
        $token   = new UsernamePasswordToken($other, 'main', $other->getRoles());

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            new UserSessionVoter()->vote($token, $session, [UserSessionVoter::MANAGE]),
        );
    }

    public function testAdminCanManageAnySession(): void
    {
        $owner = $this->makeUser(1, 'owner@example.com', 'ROLE_ORGANIZER');
        $admin = $this->makeUser(2, 'admin@example.com', 'ROLE_ADMIN');

        $session = $this->makeSession($owner);
        $token   = new UsernamePasswordToken($admin, 'main', $admin->getRoles());

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            new UserSessionVoter()->vote($token, $session, [UserSessionVoter::MANAGE]),
        );
    }

    public function testAbstainsOnUnknownAttribute(): void
    {
        $user    = $this->makeUser(1, 'user@example.com', 'ROLE_ORGANIZER');
        $session = $this->makeSession($user);
        $token   = new UsernamePasswordToken($user, 'main', $user->getRoles());

        $this->assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            new UserSessionVoter()->vote($token, $session, ['SOME_OTHER_ATTRIBUTE']),
        );
    }

    private function makeUser(int $id, string $email, string $role): User
    {
        $user = new User($email, 'Display');
        $user->addRole($role);

        $reflection = new ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);

        return $user;
    }

    private function makeSession(User $owner): UserSession
    {
        return new UserSession(
            sessId: 'voter_' . bin2hex(random_bytes(4)),
            user: $owner,
            ip: '1.2.3.4',
            userAgent: 'ua',
            userAgentDisplay: null,
            countryCode: null,
            createdAt: new DateTimeImmutable(),
        );
    }
}
