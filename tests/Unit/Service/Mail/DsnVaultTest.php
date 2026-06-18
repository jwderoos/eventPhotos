<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mail;

use InvalidArgumentException;
use App\Service\Mail\DsnVault;
use App\Service\Mail\EncryptedDsn;
use PHPUnit\Framework\TestCase;
use SodiumException;

final class DsnVaultTest extends TestCase
{
    /** 32 zero bytes — DsnVault expects the raw secretbox key, not base64. */
    private function zeroKey(): string
    {
        return str_repeat("\x00", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function testRoundTrip(): void
    {
        $vault = new DsnVault($this->zeroKey());
        $dsn = 'smtp://user:pass@example.com:587';

        $envelope = $vault->encrypt($dsn);

        $this->assertNotSame($dsn, $envelope->ciphertext);
        $this->assertSame(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, strlen($envelope->nonce));
        $this->assertSame($dsn, $vault->decrypt($envelope));
    }

    public function testTwoEncryptsProduceDifferentCiphertext(): void
    {
        $vault = new DsnVault($this->zeroKey());

        $a = $vault->encrypt('smtp://x@example.com');
        $b = $vault->encrypt('smtp://x@example.com');

        $this->assertNotSame($a->ciphertext, $b->ciphertext);
        $this->assertNotSame($a->nonce, $b->nonce);
    }

    public function testWrongKeyFailsToDecrypt(): void
    {
        $writer = new DsnVault($this->zeroKey());
        $reader = new DsnVault(str_repeat("\x01", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

        $envelope = $writer->encrypt('smtp://x@example.com');

        $this->expectException(SodiumException::class);
        $reader->decrypt($envelope);
    }

    public function testTamperedCiphertextFailsToDecrypt(): void
    {
        $vault = new DsnVault($this->zeroKey());
        $envelope = $vault->encrypt('smtp://x@example.com');
        $tampered = new EncryptedDsn(
            ciphertext: $envelope->ciphertext . 'x',
            nonce: $envelope->nonce,
        );

        $this->expectException(SodiumException::class);
        $vault->decrypt($tampered);
    }

    public function testKeyMustBe32Bytes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DsnVault('too-short');
    }
}
