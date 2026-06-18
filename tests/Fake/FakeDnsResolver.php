<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Service\Mail\DnsResolver;

final class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, list<string>> */
    private array $map = [];

    /**
     * @param list<string> $ips
     */
    public function setMapping(string $host, array $ips): void
    {
        $this->map[strtolower($host)] = $ips;
    }

    public function resolve(string $host): array
    {
        return $this->map[strtolower($host)] ?? [];
    }
}
