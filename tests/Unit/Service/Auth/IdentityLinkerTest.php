<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\AuthProvider;
use App\Enum\OAuthRefusalReason;
use App\Audit\AuditContext;
use App\Repository\UserIdentityRepository;
use App\Repository\UserRepository;
use App\Service\Auth\GoogleUserData;
use App\Service\Auth\IdentityLinker;
use App\Service\Auth\LinkRefused;
use App\Service\Auth\LoginRefused;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

final class IdentityLinkerTest extends TestCase
{
    private function noopAudit(): AuditContext
    {
        return new AuditContext(new RequestStack());
    }

    private function makeData(
        bool $verified = true,
        string $email = 'jane@example.com',
        string $sub = 'sub-123'
    ): GoogleUserData {
        return new GoogleUserData($sub, $email, $verified, 'Jane');
    }

    public function testResolveLoginRefusesUnverifiedEmail(): void
    {
        $linker = new IdentityLinker(
            $this->createStub(UserIdentityRepository::class),
            $this->createStub(UserRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->noopAudit(),
        );

        try {
            $linker->resolveLogin($this->makeData(verified: false));
            self::fail('Expected LoginRefused.');
        } catch (LoginRefused $loginRefused) {
            $this->assertSame(OAuthRefusalReason::EmailNotVerified, $loginRefused->reason);
        }
    }

    public function testResolveLoginReturnsKnownSubjectWithoutAutoLink(): void
    {
        $user = new User('jane@example.com', 'Jane');
        $identity = new UserIdentity($user, AuthProvider::Google, 'sub-123', 'jane@example.com');
        $user->addIdentity($identity);

        $identRepo = $this->createMock(UserIdentityRepository::class);
        $identRepo->expects($this->once())
            ->method('findBySubject')
            ->with(AuthProvider::Google, 'sub-123')
            ->willReturn($identity)
        ;

        $linker = new IdentityLinker(
            $identRepo,
            $this->createStub(UserRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->noopAudit(),
        );

        $result = $linker->resolveLogin($this->makeData());
        $this->assertSame($user, $result->user);
        $this->assertFalse($result->wasAutoLinked);
    }

    public function testResolveLoginRefusesWhenNoUserHasEmail(): void
    {
        $identRepo = $this->createStub(UserIdentityRepository::class);
        $identRepo->method('findBySubject')->willReturn(null);

        $userRepo = $this->createStub(UserRepository::class);
        $userRepo->method('findOneByEmail')->willReturn(null);

        $linker = new IdentityLinker(
            $identRepo,
            $userRepo,
            $this->createStub(EntityManagerInterface::class),
            $this->noopAudit(),
        );

        try {
            $linker->resolveLogin($this->makeData());
            self::fail('Expected LoginRefused.');
        } catch (LoginRefused $loginRefused) {
            $this->assertSame(OAuthRefusalReason::NoAccount, $loginRefused->reason);
        }
    }

    public function testResolveLoginRefusesWhenUserAlreadyHasDifferentGoogleIdentity(): void
    {
        $user = new User('jane@example.com', 'Jane');
        $other = new UserIdentity($user, AuthProvider::Google, 'sub-OLD', 'jane@example.com');
        $user->addIdentity($other);

        $identRepo = $this->createStub(UserIdentityRepository::class);
        $identRepo->method('findBySubject')->willReturn(null);
        $userRepo = $this->createStub(UserRepository::class);
        $userRepo->method('findOneByEmail')->willReturn($user);

        $linker = new IdentityLinker(
            $identRepo,
            $userRepo,
            $this->createStub(EntityManagerInterface::class),
            $this->noopAudit(),
        );

        try {
            $linker->resolveLogin($this->makeData(sub: 'sub-NEW'));
            self::fail('Expected LoginRefused.');
        } catch (LoginRefused $loginRefused) {
            $this->assertSame(OAuthRefusalReason::EmailBoundToOtherGoogle, $loginRefused->reason);
        }
    }

    public function testResolveLoginAutoLinksWhenUserHasNoGoogleIdentity(): void
    {
        $user = new User('jane@example.com', 'Jane');

        $identRepo = $this->createStub(UserIdentityRepository::class);
        $identRepo->method('findBySubject')->willReturn(null);
        $userRepo = $this->createStub(UserRepository::class);
        $userRepo->method('findOneByEmail')->willReturn($user);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with(self::isInstanceOf(UserIdentity::class));
        $em->expects($this->once())->method('flush');

        $linker = new IdentityLinker($identRepo, $userRepo, $em, $this->noopAudit());

        $result = $linker->resolveLogin($this->makeData());
        $this->assertSame($user, $result->user);
        $this->assertTrue($result->wasAutoLinked);
        $this->assertTrue($user->hasIdentityFor(AuthProvider::Google));
        $this->assertSame('sub-123', $user->getIdentityFor(AuthProvider::Google)?->getSubject());
    }

    public function testLinkRefusesUnverifiedEmail(): void
    {
        $linker = new IdentityLinker(
            $this->createStub(UserIdentityRepository::class),
            $this->createStub(UserRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->noopAudit(),
        );

        try {
            $linker->linkToCurrentUser(new User('a@b.test', 'A'), $this->makeData(verified: false));
            self::fail('Expected LinkRefused.');
        } catch (LinkRefused $linkRefused) {
            $this->assertSame(OAuthRefusalReason::EmailNotVerified, $linkRefused->reason);
        }
    }

    public function testLinkRefusesIfCurrentUserAlreadyHasGoogle(): void
    {
        $user = new User('a@b.test', 'A');
        $user->addIdentity(new UserIdentity($user, AuthProvider::Google, 'sub-OTHER', 'a@b.test'));

        $linker = new IdentityLinker(
            $this->createStub(UserIdentityRepository::class),
            $this->createStub(UserRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->noopAudit(),
        );

        try {
            $linker->linkToCurrentUser($user, $this->makeData());
            self::fail('Expected LinkRefused.');
        } catch (LinkRefused $linkRefused) {
            $this->assertSame(OAuthRefusalReason::AlreadyLinkedToCurrent, $linkRefused->reason);
        }
    }

    public function testLinkRefusesIfSubjectBoundToAnotherUser(): void
    {
        $current = new User('current@example.com', 'Current');
        $other = new User('other@example.com', 'Other');
        $existing = new UserIdentity($other, AuthProvider::Google, 'sub-123', 'other@example.com');

        $identRepo = $this->createStub(UserIdentityRepository::class);
        $identRepo->method('findBySubject')->willReturn($existing);

        $linker = new IdentityLinker(
            $identRepo,
            $this->createStub(UserRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->noopAudit(),
        );

        try {
            $linker->linkToCurrentUser($current, $this->makeData());
            self::fail('Expected LinkRefused.');
        } catch (LinkRefused $linkRefused) {
            $this->assertSame(OAuthRefusalReason::BoundToOtherUser, $linkRefused->reason);
        }
    }

    public function testLinkSucceedsAndAttachesIdentity(): void
    {
        $user = new User('current@example.com', 'Current');

        $identRepo = $this->createStub(UserIdentityRepository::class);
        $identRepo->method('findBySubject')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with(self::isInstanceOf(UserIdentity::class));
        $em->expects($this->once())->method('flush');

        $linker = new IdentityLinker(
            $identRepo,
            $this->createStub(UserRepository::class),
            $em,
            $this->noopAudit(),
        );

        $identity = $linker->linkToCurrentUser($user, $this->makeData());
        $this->assertSame($user, $identity->getUser());
        $this->assertSame('sub-123', $identity->getSubject());
        $this->assertTrue($user->hasIdentityFor(AuthProvider::Google));
    }
}
