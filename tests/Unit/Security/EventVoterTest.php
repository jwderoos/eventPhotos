<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Event;
use App\Entity\User;
use App\Security\Voter\EventVoter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class EventVoterTest extends TestCase
{
    public function testOwnerCanEdit(): void
    {
        $owner = new User('owner@example.com', 'Owner');
        $event = new Event(
            'e',
            'E',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnMap([['ROLE_ADMIN', null, false]]);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($owner);

        $voter = new EventVoter($security);

        $this->assertSame(1, $voter->vote($token, $event, [EventVoter::EDIT]));
    }

    public function testNonOwnerCannotEdit(): void
    {
        $owner    = new User('owner@example.com', 'Owner');
        $intruder = new User('intruder@example.com', 'Intruder');
        $event = new Event(
            'e',
            'E',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnMap([['ROLE_ADMIN', null, false]]);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($intruder);

        $voter = new EventVoter($security);

        $this->assertSame(-1, $voter->vote($token, $event, [EventVoter::EDIT]));
    }

    public function testAdminAlwaysAllowed(): void
    {
        $owner = new User('owner@example.com', 'Owner');
        $admin = new User('admin@example.com', 'Admin');
        $event = new Event(
            'e',
            'E',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnMap([['ROLE_ADMIN', null, true]]);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($admin);

        $voter = new EventVoter($security);

        $this->assertSame(1, $voter->vote($token, $event, [EventVoter::EDIT]));
    }

    public function testUnsupportedAttributeAbstains(): void
    {
        $owner = new User('owner@example.com', 'Owner');
        $event = new Event(
            'e',
            'E',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );

        $security = $this->createStub(Security::class);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($owner);

        $voter = new EventVoter($security);

        $this->assertSame(0, $voter->vote($token, $event, ['SOME_OTHER_PERMISSION']));
    }
}
