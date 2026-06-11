<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Invitation;
use App\Entity\InvitationStatus;
use App\Entity\User;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InvitationTest extends TestCase
{
    public function testFreshInvitationIsPending(): void
    {
        $invite = $this->makeInvite();

        $this->assertSame(InvitationStatus::Pending, $invite->status());
        $this->assertTrue($invite->isPending());
    }

    public function testExpiredInvitationReportsExpired(): void
    {
        $invite = $this->makeInvite(expiresAt: new DateTimeImmutable('-1 hour'));

        $this->assertSame(InvitationStatus::Expired, $invite->status());
        $this->assertFalse($invite->isPending());
    }

    public function testRevokeMarksInvitationRevoked(): void
    {
        $admin = $this->makeUser('admin@example.com');
        $invite = $this->makeInvite();

        $invite->revoke($admin);

        $this->assertSame(InvitationStatus::Revoked, $invite->status());
        $this->assertSame($admin, $invite->getRevokedBy());
        $this->assertInstanceOf(DateTimeImmutable::class, $invite->getRevokedAt());
    }

    public function testMarkUsedTransitionsToUsed(): void
    {
        $newUser = $this->makeUser('new@example.com');
        $invite = $this->makeInvite();

        $invite->markUsed($newUser, 'new@example.com');

        $this->assertSame(InvitationStatus::Used, $invite->status());
        $this->assertSame($newUser, $invite->getUsedBy());
        $this->assertSame('new@example.com', $invite->getEmail());
        $this->assertInstanceOf(DateTimeImmutable::class, $invite->getUsedAt());
    }

    public function testRevokeThrowsWhenAlreadyUsed(): void
    {
        $newUser = $this->makeUser('new@example.com');
        $admin = $this->makeUser('admin@example.com');
        $invite = $this->makeInvite();
        $invite->markUsed($newUser, 'new@example.com');

        $this->expectException(DomainException::class);
        $invite->revoke($admin);
    }

    public function testMarkUsedThrowsWhenAlreadyRevoked(): void
    {
        $admin = $this->makeUser('admin@example.com');
        $newUser = $this->makeUser('new@example.com');
        $invite = $this->makeInvite();
        $invite->revoke($admin);

        $this->expectException(DomainException::class);
        $invite->markUsed($newUser, 'new@example.com');
    }

    public function testRevokeThrowsWhenAlreadyRevoked(): void
    {
        $admin = $this->makeUser('admin@example.com');
        $invite = $this->makeInvite();
        $invite->revoke($admin);

        $this->expectException(DomainException::class);
        $invite->revoke($admin);
    }

    public function testMarkUsedThrowsWhenExpired(): void
    {
        $newUser = $this->makeUser('new@example.com');
        $invite = $this->makeInvite(expiresAt: new DateTimeImmutable('-1 minute'));

        $this->expectException(DomainException::class);
        $invite->markUsed($newUser, 'new@example.com');
    }

    public function testConstructorRejectsEmptySelector(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Invitation(
            selector: '',
            hashedVerifier: str_repeat('a', 64),
            role: 'ROLE_ORGANIZER',
            createdBy: $this->makeUser('admin@example.com'),
            expiresAt: new DateTimeImmutable('+7 days'),
        );
    }

    public function testConstructorRejectsEmptyHashedVerifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Invitation(
            selector: str_repeat('a', 32),
            hashedVerifier: '',
            role: 'ROLE_ORGANIZER',
            createdBy: $this->makeUser('admin@example.com'),
            expiresAt: new DateTimeImmutable('+7 days'),
        );
    }

    public function testConstructorRejectsUnknownRole(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Invitation(
            selector: str_repeat('a', 32),
            hashedVerifier: str_repeat('b', 64),
            role: 'ROLE_ROOT',
            createdBy: $this->makeUser('admin@example.com'),
            expiresAt: new DateTimeImmutable('+7 days'),
        );
    }

    private function makeInvite(?DateTimeImmutable $expiresAt = null): Invitation
    {
        return new Invitation(
            selector: str_repeat('a', 32),
            hashedVerifier: str_repeat('b', 64),
            role: 'ROLE_ORGANIZER',
            createdBy: $this->makeUser('admin@example.com'),
            expiresAt: $expiresAt ?? new DateTimeImmutable('+7 days'),
        );
    }

    private function makeUser(string $email): User
    {
        return new User($email, 'Display');
    }
}
