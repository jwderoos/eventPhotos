<?php

declare(strict_types=1);

namespace App\Service\Mail;

interface DnsResolver
{
    /**
     * @return list<string> IPv4 + IPv6 addresses the host resolves to; empty for NXDOMAIN.
     */
    public function resolve(string $host): array;
}
