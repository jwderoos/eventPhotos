<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mail;

use App\Service\Mail\PublicIpInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicIpInspectorTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function publicIps(): iterable
    {
        yield 'v4 example.com' => ['93.184.216.34'];
        yield 'v4 google dns' => ['8.8.8.8'];
        yield 'v6 cloudflare' => ['2606:4700:4700::1111'];
        yield 'v6 mapped public' => ['::ffff:8.8.8.8'];
        yield 'v6 6to4 public' => ['2002:0808:0808::'];
        yield 'v6 teredo public' => ['2001:0000::f7f7:f7f7'];
    }

    #[DataProvider('publicIps')]
    public function testAcceptsPublicIps(string $ip): void
    {
        $this->assertTrue(new PublicIpInspector()->isPublic($ip));
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedIps(): iterable
    {
        yield 'v4 loopback' => ['127.0.0.1'];
        yield 'v4 rfc1918 10' => ['10.0.0.1'];
        yield 'v4 rfc1918 192.168' => ['192.168.1.1'];
        yield 'v4 rfc1918 172.16' => ['172.16.0.1'];
        yield 'v4 link-local' => ['169.254.169.254'];
        yield 'v4 cgnat' => ['100.64.0.1'];
        yield 'v4 cgnat top' => ['100.127.255.255'];
        yield 'v4 multicast' => ['224.0.0.1'];
        yield 'v4 reserved 240' => ['240.0.0.1'];
        yield 'v4 broadcast' => ['255.255.255.255'];
        yield 'v4 unspecified' => ['0.0.0.0'];
        yield 'v6 loopback' => ['::1'];
        yield 'v6 unspecified' => ['::'];
        yield 'v6 link-local' => ['fe80::1'];
        yield 'v6 ula' => ['fc00::1'];
        yield 'v6 multicast' => ['ff02::1'];
        yield 'v6 mapped loopback' => ['::ffff:127.0.0.1'];
        yield 'v6 mapped metadata' => ['::ffff:169.254.169.254'];
        yield 'v6 mapped rfc1918' => ['::ffff:10.0.0.1'];
        yield 'v6 6to4 loopback' => ['2002:7f00:0001::'];
        yield 'v6 teredo rfc1918' => ['2001:0000:4136:e378:8000:63bf:3fff:fdd2'];
        yield 'garbage' => ['not-an-ip'];
        yield 'empty' => [''];
    }

    #[DataProvider('rejectedIps')]
    public function testRejectsNonPublicIps(string $ip): void
    {
        $this->assertFalse(new PublicIpInspector()->isPublic($ip));
    }
}
