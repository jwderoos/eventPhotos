<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\AuthProvider;
use PHPUnit\Framework\TestCase;

final class UserIdentityHelpersTest extends TestCase
{
    public function testHasIdentityForReturnsFalseForFreshUser(): void
    {
        $user = new User('a@b.test', 'Test');
        $this->assertFalse($user->hasIdentityFor(AuthProvider::Google));
        $this->assertNotInstanceOf(UserIdentity::class, $user->getIdentityFor(AuthProvider::Google));
    }

    public function testHasIdentityForReturnsTrueWhenIdentityIsAttached(): void
    {
        $user = new User('a@b.test', 'Test');
        $identity = new UserIdentity($user, AuthProvider::Google, 'sub-123', 'a@b.test');
        $user->addIdentity($identity);

        $this->assertTrue($user->hasIdentityFor(AuthProvider::Google));
        $this->assertSame($identity, $user->getIdentityFor(AuthProvider::Google));
    }

    public function testHasUsablePasswordIsFalseWhenPasswordIsEmptyString(): void
    {
        $user = new User('a@b.test', 'Test');
        // Default password = '' from the entity constructor
        $this->assertFalse($user->hasUsablePassword());
    }

    public function testHasUsablePasswordIsTrueOnceSet(): void
    {
        $user = new User('a@b.test', 'Test');
        $user->setPassword('$2y$10$abcdefghijklmnopqrstuvwx');
        $this->assertTrue($user->hasUsablePassword());
    }
}
