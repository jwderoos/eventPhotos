<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Invitation;

use App\Service\Invitation\InvitationTokenService;
use PHPUnit\Framework\TestCase;

final class InvitationTokenServiceTest extends TestCase
{
    public function testGenerateProducesParseableToken(): void
    {
        $service = new InvitationTokenService();
        $generated = $service->generate();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.[a-f0-9]{64}$/', $generated->plaintext);
        $this->assertSame(32, strlen($generated->selector));
        $this->assertSame(64, strlen($generated->hashedVerifier));

        $parsed = $service->parse($generated->plaintext);
        $this->assertNotNull($parsed);
        $this->assertSame($generated->selector, $parsed['selector']);
    }

    public function testParseRejectsMalformedTokens(): void
    {
        $service = new InvitationTokenService();

        $this->assertNull($service->parse(''));
        $this->assertNull($service->parse('no-dot-here'));
        $this->assertNull($service->parse('.'));
        $this->assertNull($service->parse('aa.bb.cc'));
        $this->assertNull($service->parse('aa.'));
        $this->assertNull($service->parse('.bb'));
        $this->assertNull($service->parse('NOT-HEX.NOT-HEX'));
        $this->assertNull($service->parse('aabbccdd.1234'));               // both segments hex but too short
        $this->assertNull($service->parse(str_repeat('a', 31) . '.' . str_repeat('b', 64))); // selector 31 chars
        $this->assertNull($service->parse(str_repeat('a', 32) . '.' . str_repeat('b', 63))); // verifier 63 chars
    }

    public function testVerifySucceedsForMatchingPair(): void
    {
        $service = new InvitationTokenService();
        $generated = $service->generate();
        $parsed = $service->parse($generated->plaintext);

        $this->assertNotNull($parsed);
        $this->assertTrue($service->verify($generated->hashedVerifier, $parsed['verifier']));
    }

    public function testVerifyFailsForTamperedVerifier(): void
    {
        $service = new InvitationTokenService();
        $generated = $service->generate();
        $parsed = $service->parse($generated->plaintext);

        $this->assertNotNull($parsed);
        $tampered = str_repeat('0', strlen($parsed['verifier']));
        $this->assertFalse($service->verify($generated->hashedVerifier, $tampered));
    }

    public function testVerifyFailsForEmptyVerifier(): void
    {
        $service = new InvitationTokenService();
        $generated = $service->generate();

        $this->assertFalse($service->verify($generated->hashedVerifier, ''));
    }

    public function testTwoGenerationsProduceDifferentTokens(): void
    {
        $service = new InvitationTokenService();
        $a = $service->generate();
        $b = $service->generate();

        $this->assertNotSame($a->selector, $b->selector);
        $this->assertNotSame($a->hashedVerifier, $b->hashedVerifier);
    }
}
