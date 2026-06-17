<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Session;

use App\Service\Session\CountryResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CountryResolverTest extends TestCase
{
    public function testReturnsNullWhenMmdbMissing(): void
    {
        $resolver = new CountryResolver('/nonexistent/path/to/GeoLite2-Country.mmdb', new NullLogger());
        $this->assertNull($resolver->resolve('8.8.8.8'));
    }

    #[DataProvider('privateAndLoopbackIps')]
    public function testReturnsNullForPrivateOrLoopbackIpsWithoutReaderCall(string $ip): void
    {
        $resolver = new CountryResolver(__DIR__ . '/does-not-exist.mmdb', new NullLogger());
        $this->assertNull($resolver->resolve($ip));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function privateAndLoopbackIps(): iterable
    {
        yield 'IPv4 loopback' => ['127.0.0.1'];
        yield 'IPv4 private 10/8' => ['10.0.0.5'];
        yield 'IPv4 private 192.168/16' => ['192.168.1.1'];
        yield 'IPv4 private 172.16/12' => ['172.20.0.1'];
        yield 'IPv6 loopback' => ['::1'];
        yield 'malformed IP' => ['not-an-ip'];
    }
}
