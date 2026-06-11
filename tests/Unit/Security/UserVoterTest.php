<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\Voter\UserVoter;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class UserVoterTest extends TestCase
{
    public function testAdminCanViewAndEditOthers(): void
    {
        $admin  = $this->makeUser(1, 'admin@example.com');
        $target = $this->makeUser(2, 'target@example.com');

        $voter = new UserVoter($this->securityWithAdmin(true));
        $token = $this->tokenFor($admin);

        $this->assertSame(1, $voter->vote($token, $target, [UserVoter::VIEW]));
        $this->assertSame(1, $voter->vote($token, $target, [UserVoter::EDIT]));
    }

    public function testAdminCannotEditOwnRole(): void
    {
        $admin = $this->makeUser(1, 'admin@example.com');

        $voter = new UserVoter($this->securityWithAdmin(true));
        $token = $this->tokenFor($admin);

        $this->assertSame(-1, $voter->vote($token, $admin, [UserVoter::EDIT_ROLE]));
    }

    public function testAdminCannotDeleteSelf(): void
    {
        $admin = $this->makeUser(1, 'admin@example.com');

        $voter = new UserVoter($this->securityWithAdmin(true));
        $token = $this->tokenFor($admin);

        $this->assertSame(-1, $voter->vote($token, $admin, [UserVoter::DELETE]));
    }

    public function testAdminCanEditRoleAndDeleteOtherAdmin(): void
    {
        $admin = $this->makeUser(1, 'admin@example.com');
        $other = $this->makeUser(2, 'other@example.com');

        $voter = new UserVoter($this->securityWithAdmin(true));
        $token = $this->tokenFor($admin);

        $this->assertSame(1, $voter->vote($token, $other, [UserVoter::EDIT_ROLE]));
        $this->assertSame(1, $voter->vote($token, $other, [UserVoter::DELETE]));
    }

    public function testOrganizerDeniedForEveryAttribute(): void
    {
        $organizer = $this->makeUser(1, 'org@example.com');
        $target    = $this->makeUser(2, 'target@example.com');

        $voter = new UserVoter($this->securityWithAdmin(false));
        $token = $this->tokenFor($organizer);

        foreach ([UserVoter::VIEW, UserVoter::EDIT, UserVoter::EDIT_ROLE, UserVoter::DELETE] as $attr) {
            $this->assertSame(-1, $voter->vote($token, $target, [$attr]), $attr);
        }
    }

    public function testUnsupportedAttributeAbstains(): void
    {
        $user = $this->makeUser(1, 'a@example.com');

        $voter = new UserVoter($this->securityWithAdmin(true));
        $token = $this->tokenFor($user);

        $this->assertSame(0, $voter->vote($token, $user, ['SOMETHING_ELSE']));
    }

    private function makeUser(int $id, string $email): User
    {
        $user = new User($email, 'Display');
        $reflection = new ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);
        return $user;
    }

    private function securityWithAdmin(bool $isAdmin): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnMap([['ROLE_ADMIN', null, $isAdmin]]);
        return $security;
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        return $token;
    }
}
