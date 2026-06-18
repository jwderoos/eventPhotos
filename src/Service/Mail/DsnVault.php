<?php

declare(strict_types=1);

namespace App\Service\Mail;

use InvalidArgumentException;
use SensitiveParameter;
use SodiumException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class DsnVault
{
    private string $key;

    public function __construct(
        #[SensitiveParameter]
        #[Autowire('%env(base64:MAIL_CONFIG_ENCRYPTION_KEY)%')]
        string $keyRaw,
    ) {
        if (strlen($keyRaw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new InvalidArgumentException(sprintf(
                'MAIL_CONFIG_ENCRYPTION_KEY must decode to %d bytes; got %d.',
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                strlen($keyRaw),
            ));
        }

        $this->key = $keyRaw;
    }

    public function encrypt(#[SensitiveParameter] string $dsn): EncryptedDsn
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($dsn, $nonce, $this->key);

        return new EncryptedDsn(ciphertext: $ciphertext, nonce: $nonce);
    }

    public function decrypt(EncryptedDsn $envelope): string
    {
        $plaintext = sodium_crypto_secretbox_open($envelope->ciphertext, $envelope->nonce, $this->key);
        if ($plaintext === false) {
            throw new SodiumException('Mail-config DSN ciphertext could not be decrypted (wrong key or tampered).');
        }

        return $plaintext;
    }
}
