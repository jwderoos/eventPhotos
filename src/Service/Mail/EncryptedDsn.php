<?php

declare(strict_types=1);

namespace App\Service\Mail;

use InvalidArgumentException;

final readonly class EncryptedDsn
{
    public function __construct(
        public string $ciphertext,
        public string $nonce,
    ) {
        if ($ciphertext === '') {
            throw new InvalidArgumentException('EncryptedDsn ciphertext cannot be empty.');
        }

        if ($nonce === '') {
            throw new InvalidArgumentException('EncryptedDsn nonce cannot be empty.');
        }
    }
}
