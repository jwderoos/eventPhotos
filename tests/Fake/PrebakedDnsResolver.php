<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Service\Mail\DnsResolver;

final class PrebakedDnsResolver implements DnsResolver
{
    /** Host → resolved IPs. Most-specific suffixes first. */
    private const array MAP = [
        '.loopback.rebind.example-organizer.test' => ['127.0.0.1'],
        '.mapped.rebind.example-organizer.test' => ['::ffff:169.254.169.254'],
        '.cgnat.rebind.example-organizer.test' => ['100.64.0.1'],
        'smtp.fail.example-organizer.test' => ['93.184.216.35'],
        'smtp.gmail.com' => ['93.184.216.40'],
    ];

    public function resolve(string $host): array
    {
        $host = strtolower($host);

        foreach (self::MAP as $needle => $ips) {
            if ($host === ltrim($needle, '.') || str_ends_with($host, $needle)) {
                return $ips;
            }
        }

        if (str_ends_with($host, '.example-organizer.test')) {
            return ['93.184.216.34'];
        }

        if (preg_match('/^\d+\.\d+\.\d+\.\d+$/', $host) === 1) {
            return [$host];
        }

        return [];
    }
}
