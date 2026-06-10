<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\User;
use App\Security\Voter\PhotoVoter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class PhotoVoterTest extends TestCase
{
    public function testOwnerCanEdit(): void
    {
        $owner = $this->makeUser('owner@example.test');
        $photo = $this->makePhoto($owner);

        $voter = new PhotoVoter($this->securityMock(false));
        $token = $this->tokenWith($owner);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $photo, [PhotoVoter::EDIT]));
    }

    public function testStrangerCannotEdit(): void
    {
        $owner    = $this->makeUser('owner@example.test');
        $stranger = $this->makeUser('stranger@example.test');
        $photo    = $this->makePhoto($owner);

        $voter = new PhotoVoter($this->securityMock(false));
        $token = $this->tokenWith($stranger);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $photo, [PhotoVoter::EDIT]));
    }

    public function testAdminCanEdit(): void
    {
        $owner    = $this->makeUser('owner@example.test');
        $stranger = $this->makeUser('stranger@example.test');
        $photo    = $this->makePhoto($owner);

        $voter = new PhotoVoter($this->securityMock(true));
        $token = $this->tokenWith($stranger);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $photo, [PhotoVoter::EDIT]));
    }

    private function makeUser(string $email): User
    {
        $u = new User($email, 'Name');
        $u->setPassword('x');
        return $u;
    }

    private function makePhoto(User $owner): Photo
    {
        $event = new Event('e', 'E', new DateTimeImmutable('2026-06-10'), $owner);
        return new Photo($event, str_repeat('a', 64), 'x.jpg', 100);
    }

    private function tokenWith(User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        return $token;
    }

    private function securityMock(bool $isAdmin): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn($isAdmin);
        return $security;
    }
}
