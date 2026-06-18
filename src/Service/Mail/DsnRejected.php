<?php

declare(strict_types=1);

namespace App\Service\Mail;

use RuntimeException;

final class DsnRejected extends RuntimeException
{
    public const string REASON_SCHEME = 'scheme';

    public const string REASON_HOST = 'host';

    public const string REASON_UNRESOLVABLE = 'unresolvable';

    public const string REASON_MALFORMED = 'malformed';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function scheme(string $scheme): self
    {
        return new self(
            self::REASON_SCHEME,
            sprintf('Unsupported mail transport scheme "%s". Only smtp and smtps are allowed.', $scheme),
        );
    }

    public static function host(string $host, string $rejectedIp): self
    {
        return new self(
            self::REASON_HOST,
            sprintf('Mail transport host "%s" resolves to a non-public address (%s).', $host, $rejectedIp),
        );
    }

    public static function unresolvable(string $host): self
    {
        return new self(
            self::REASON_UNRESOLVABLE,
            sprintf('Mail transport host "%s" does not resolve.', $host),
        );
    }

    public static function malformed(string $why): self
    {
        return new self(self::REASON_MALFORMED, sprintf('Mail DSN is malformed: %s.', $why));
    }
}
