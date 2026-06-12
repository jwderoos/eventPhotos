<?php

declare(strict_types=1);

namespace App\Service\Invitation;

class InvitationTokenService
{
    private const int SELECTOR_BYTES = 16;

    private const int VERIFIER_BYTES = 32;

    public function generate(): GeneratedToken
    {
        $selector = bin2hex(random_bytes(self::SELECTOR_BYTES));
        $verifier = bin2hex(random_bytes(self::VERIFIER_BYTES));
        $hashedVerifier = hash('sha256', $verifier);

        return new GeneratedToken(
            plaintext: $selector . '.' . $verifier,
            selector: $selector,
            hashedVerifier: $hashedVerifier,
        );
    }

    /**
     * @return array{selector: string, verifier: string}|null
     */
    public function parse(string $plaintext): ?array
    {
        if (!preg_match('/^([a-f0-9]{32})\.([a-f0-9]{64})$/', $plaintext, $m)) {
            return null;
        }

        return ['selector' => $m[1], 'verifier' => $m[2]];
    }

    public function verify(string $storedHashedVerifier, string $presentedVerifier): bool
    {
        if ($presentedVerifier === '') {
            return false;
        }

        return hash_equals($storedHashedVerifier, hash('sha256', $presentedVerifier));
    }
}
