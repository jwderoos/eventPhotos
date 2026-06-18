<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Service\Mail\DnsResolver;

final class PrebakedDnsResolver implements DnsResolver
{
    public function resolve(string $host): array
    {
        $host = strtolower($host);

        if (str_ends_with($host, '.example-organizer.test')) {
            return ['93.184.216.34'];
        }

        if (preg_match('/^\d+\.\d+\.\d+\.\d+$/', $host) === 1) {
            return [$host];
        }

        return [];
    }
}
