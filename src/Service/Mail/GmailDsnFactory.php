<?php

declare(strict_types=1);

namespace App\Service\Mail;

use SensitiveParameter;

final class GmailDsnFactory
{
    private const string HOST = 'smtp.gmail.com';

    private const int PORT = 465;

    public function build(string $email, #[SensitiveParameter] string $appPassword): string
    {
        // Strip ASCII *and* Unicode whitespace: Google's app-password UI separates the
        // four groups with non-breaking spaces (U+00A0 / U+202F), which the non-Unicode
        // \s does not match — they would survive, get URL-encoded, and corrupt the secret.
        $password = (string) preg_replace('/[\s\p{Z}]+/u', '', $appPassword);

        return sprintf(
            'smtps://%s:%s@%s:%d',
            rawurlencode($email),
            rawurlencode($password),
            self::HOST,
            self::PORT,
        );
    }
}
