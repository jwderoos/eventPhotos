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
        $password = (string) preg_replace('/\s+/', '', $appPassword);

        return sprintf(
            'smtps://%s:%s@%s:%d',
            rawurlencode($email),
            rawurlencode($password),
            self::HOST,
            self::PORT,
        );
    }
}
