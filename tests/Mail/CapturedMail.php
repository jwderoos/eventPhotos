<?php

declare(strict_types=1);

namespace App\Tests\Mail;

use RuntimeException;
use Symfony\Component\Mime\RawMessage;
use Throwable;

final class CapturedMail
{
    /** @var array<string, list<RawMessage>> */
    private static array $byHost = [];

    /** @var array<string, Throwable> */
    private static array $throwOnHost = [];

    /** @return list<RawMessage> */
    public static function messagesForHost(string $host): array
    {
        return self::$byHost[strtolower($host)] ?? [];
    }

    public static function record(string $host, RawMessage $message): void
    {
        $key = strtolower($host);
        if (isset(self::$throwOnHost[$key])) {
            throw self::$throwOnHost[$key];
        }

        self::$byHost[$key][] = $message;
    }

    public static function throwOnHost(string $host, Throwable $e): void
    {
        self::$throwOnHost[strtolower($host)] = $e;
    }

    public static function reset(): void
    {
        self::$byHost = [];
        self::$throwOnHost = [];
    }

    public static function assertCapturedForHost(string $host, int $expected): void
    {
        $actual = count(self::messagesForHost($host));
        if ($actual !== $expected) {
            throw new RuntimeException(sprintf(
                'Expected %d messages for host %s, got %d.',
                $expected,
                $host,
                $actual,
            ));
        }
    }
}
