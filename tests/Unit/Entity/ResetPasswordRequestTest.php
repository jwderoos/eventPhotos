<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ResetPasswordRequestTest extends TestCase
{
    public function testExposesInterfaceAccessors(): void
    {
        $user    = new User('alice@example.com', 'Alice');
        $expires = new DateTimeImmutable('2099-01-01T00:00:00+00:00');

        $req = new ResetPasswordRequest($user, $expires, 'selector-value', 'hashed-token-value');

        $this->assertSame($user, $req->getUser());
        $this->assertSame('hashed-token-value', $req->getHashedToken());
        $this->assertEquals($expires, $req->getExpiresAt());
        $this->assertInstanceOf(DateTimeImmutable::class, $req->getRequestedAt());
        $this->assertFalse($req->isExpired());
    }

    public function testIsExpiredReturnsTrueWhenExpiryHasPassed(): void
    {
        $user    = new User('alice@example.com', 'Alice');
        $expires = new DateTimeImmutable('2000-01-01T00:00:00+00:00');

        $req = new ResetPasswordRequest($user, $expires, 'sel', 'tok');

        $this->assertTrue($req->isExpired());
    }
}
