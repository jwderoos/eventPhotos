<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mail;

use InvalidArgumentException;
use App\Service\Mail\EncryptedDsn;
use PHPUnit\Framework\TestCase;

final class EncryptedDsnTest extends TestCase
{
    public function testHoldsCiphertextAndNonce(): void
    {
        $envelope = new EncryptedDsn(ciphertext: 'c', nonce: 'n');

        $this->assertSame('c', $envelope->ciphertext);
        $this->assertSame('n', $envelope->nonce);
    }

    public function testRejectsEmptyCiphertext(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EncryptedDsn(ciphertext: '', nonce: 'n');
    }

    public function testRejectsEmptyNonce(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EncryptedDsn(ciphertext: 'c', nonce: '');
    }
}
