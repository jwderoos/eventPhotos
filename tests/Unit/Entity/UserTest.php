<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testNewUserHasUserRoleByDefault(): void
    {
        $user = new User('alice@example.com', 'Alice');

        $this->assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testAddingOrganizerRoleKeepsUserRole(): void
    {
        $user = new User('alice@example.com', 'Alice');
        $user->addRole('ROLE_ORGANIZER');

        $this->assertContains('ROLE_ORGANIZER', $user->getRoles());
        $this->assertContains('ROLE_USER', $user->getRoles());
    }

    public function testAddingSameRoleTwiceDoesNotDuplicate(): void
    {
        $user = new User('alice@example.com', 'Alice');
        $user->addRole('ROLE_ORGANIZER');
        $user->addRole('ROLE_ORGANIZER');

        $this->assertCount(1, array_filter($user->getRoles(), static fn (string $r): bool => $r === 'ROLE_ORGANIZER'));
    }

    public function testUserIdentifierIsEmail(): void
    {
        $user = new User('alice@example.com', 'Alice');

        $this->assertSame('alice@example.com', $user->getUserIdentifier());
    }
}
