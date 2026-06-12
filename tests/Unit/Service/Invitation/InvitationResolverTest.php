<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Invitation;

use App\Entity\Invitation;
use App\Repository\InvitationRepository;
use App\Service\Invitation\InvitationResolver;
use App\Service\Invitation\InvitationTokenService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class InvitationResolverTest extends TestCase
{
    public function testReturnsNullOnMalformedToken(): void
    {
        $tokens = $this->createStub(InvitationTokenService::class);
        $tokens->method('parse')->willReturn(null);

        $resolver = new InvitationResolver(
            $this->createStub(InvitationRepository::class),
            $tokens,
            new NullLogger(),
        );
        $this->assertNotInstanceOf(Invitation::class, $resolver->resolveValid('garbage'));
    }

    public function testReturnsNullWhenSelectorUnknown(): void
    {
        $tokens = $this->createStub(InvitationTokenService::class);
        $tokens->method('parse')->willReturn(['selector' => 'abcd', 'verifier' => 'eeff']);

        $repo = $this->createStub(InvitationRepository::class);
        $repo->method('findBySelector')->willReturn(null);

        $resolver = new InvitationResolver($repo, $tokens, new NullLogger());
        $this->assertNotInstanceOf(Invitation::class, $resolver->resolveValid('abcd.eeff'));
    }

    public function testReturnsNullOnVerifierMismatch(): void
    {
        $invite = $this->createStub(Invitation::class);
        $invite->method('getId')->willReturn(1);
        $invite->method('getHashedVerifier')->willReturn('hashed');

        $tokens = $this->createStub(InvitationTokenService::class);
        $tokens->method('parse')->willReturn(['selector' => 'abcd', 'verifier' => 'eeff']);
        $tokens->method('verify')->willReturn(false);

        $repo = $this->createStub(InvitationRepository::class);
        $repo->method('findBySelector')->willReturn($invite);

        $resolver = new InvitationResolver($repo, $tokens, new NullLogger());
        $this->assertNotInstanceOf(Invitation::class, $resolver->resolveValid('abcd.eeff'));
    }

    public function testReturnsInvitationWhenValid(): void
    {
        $invite = $this->createStub(Invitation::class);
        $invite->method('getHashedVerifier')->willReturn('hashed');
        $invite->method('isPending')->willReturn(true);

        $tokens = $this->createStub(InvitationTokenService::class);
        $tokens->method('parse')->willReturn(['selector' => 'abcd', 'verifier' => 'eeff']);
        $tokens->method('verify')->willReturn(true);

        $repo = $this->createStub(InvitationRepository::class);
        $repo->method('findBySelector')->willReturn($invite);

        $resolver = new InvitationResolver($repo, $tokens, new NullLogger());
        $this->assertSame($invite, $resolver->resolveValid('abcd.eeff'));
    }
}
