<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use DateTimeImmutable;
use InvalidArgumentException;
use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Service\Mail\EncryptedDsn;
use DomainException;
use PHPUnit\Framework\TestCase;

final class UserMailConfigTest extends TestCase
{
    private function newUser(): User
    {
        return new User('owner@example.com', 'Owner');
    }

    private function newConfig(): UserMailConfig
    {
        return new UserMailConfig(
            $this->newUser(),
            new EncryptedDsn(ciphertext: 'cipher', nonce: 'nonce-bytes'),
            'sender@example.com',
            'Sender Display',
        );
    }

    public function testNewConfigIsUnverifiedAndHasToken(): void
    {
        $config = $this->newConfig();

        $this->assertNotInstanceOf(DateTimeImmutable::class, $config->getVerifiedAt());
        $this->assertNotNull($config->getVerificationToken());
        $this->assertInstanceOf(DateTimeImmutable::class, $config->getVerificationSentAt());
        $this->assertSame(43, strlen($config->getVerificationToken()));
    }

    public function testMarkVerifiedClearsTokenAndSetsTimestamp(): void
    {
        $config = $this->newConfig();

        $config->markVerified();

        $this->assertInstanceOf(DateTimeImmutable::class, $config->getVerifiedAt());
        $this->assertNull($config->getVerificationToken());
    }

    public function testCannotVerifyTwice(): void
    {
        $config = $this->newConfig();
        $config->markVerified();

        $this->expectException(DomainException::class);
        $config->markVerified();
    }

    public function testRegenerateVerificationTokenRotates(): void
    {
        $config = $this->newConfig();
        $oldToken = $config->getVerificationToken();
        $oldSentAt = $config->getVerificationSentAt();

        usleep(1000);
        $config->regenerateVerificationToken();

        $this->assertNotSame($oldToken, $config->getVerificationToken());
        $this->assertNotSame($oldSentAt, $config->getVerificationSentAt());
        $this->assertNotInstanceOf(DateTimeImmutable::class, $config->getVerifiedAt());
    }

    public function testRegenerateAfterVerifyResetsVerifiedAt(): void
    {
        $config = $this->newConfig();
        $config->markVerified();

        $config->regenerateVerificationToken();

        $this->assertNotInstanceOf(DateTimeImmutable::class, $config->getVerifiedAt());
        $this->assertNotNull($config->getVerificationToken());
    }

    public function testApplyConfigReturnsTrueWhenDsnChanges(): void
    {
        $config = $this->newConfig();
        $config->markVerified();

        $requiresReverify = $config->applyConfig(
            new EncryptedDsn(ciphertext: 'cipher2', nonce: 'nonce2-bytes'),
            'sender@example.com',
            'Sender Display',
        );

        $this->assertTrue($requiresReverify);
        $this->assertNotInstanceOf(DateTimeImmutable::class, $config->getVerifiedAt());
    }

    public function testApplyConfigReturnsTrueWhenFromAddrChanges(): void
    {
        $config = $this->newConfig();
        $config->markVerified();

        $requiresReverify = $config->applyConfig(
            new EncryptedDsn(ciphertext: 'cipher', nonce: 'nonce-bytes'),
            'newsender@example.com',
            'Sender Display',
        );

        $this->assertTrue($requiresReverify);
    }

    public function testApplyConfigReturnsFalseWhenOnlyFromNameChanges(): void
    {
        $config = $this->newConfig();
        $config->markVerified();

        $verifiedAt = $config->getVerifiedAt();

        $requiresReverify = $config->applyConfig(
            new EncryptedDsn(ciphertext: 'cipher', nonce: 'nonce-bytes'),
            'sender@example.com',
            'New Display Name',
        );

        $this->assertFalse($requiresReverify);
        $this->assertSame($verifiedAt, $config->getVerifiedAt());
    }

    public function testFromAddrRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UserMailConfig(
            $this->newUser(),
            new EncryptedDsn(ciphertext: 'c', nonce: 'n'),
            '',
            null,
        );
    }

    public function testGetEncryptedDsnRoundTripsBase64Storage(): void
    {
        $config = new UserMailConfig(
            $this->newUser(),
            new EncryptedDsn(ciphertext: "binary\x00\xff", nonce: "nonce\x01"),
            'sender@example.com',
            null,
        );

        $envelope = $config->getEncryptedDsn();

        $this->assertSame("binary\x00\xff", $envelope->ciphertext);
        $this->assertSame("nonce\x01", $envelope->nonce);
    }
}
