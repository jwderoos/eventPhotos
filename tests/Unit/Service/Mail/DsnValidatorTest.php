<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mail;

use App\Service\Mail\DsnRejected;
use App\Service\Mail\DsnValidator;
use App\Tests\Fake\FakeDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DsnValidatorTest extends TestCase
{
    public function testAcceptsPublicSmtp(): void
    {
        $dns = new FakeDnsResolver();
        $dns->setMapping('smtp.example.com', ['93.184.216.34']);

        $validator = new DsnValidator($dns);

        $validator->validate('smtp://user:pass@smtp.example.com:25');

        $this->expectNotToPerformAssertions();
    }

    public function testAcceptsPublicSmtps(): void
    {
        $dns = new FakeDnsResolver();
        $dns->setMapping('smtp.example.com', ['93.184.216.34']);

        $validator = new DsnValidator($dns);

        $validator->validate('smtps://user:pass@smtp.example.com:465');

        $this->expectNotToPerformAssertions();
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedSchemes(): iterable
    {
        yield 'http' => ['http://x@example.com'];
        yield 'null' => ['null://null'];
        yield 'gmail+smtp' => ['gmail+smtp://user@gmail.com'];
        yield 'sendgrid+api' => ['sendgrid+api://KEY@default'];
        yield 'mailto' => ['mailto://x@example.com'];
    }

    #[DataProvider('rejectedSchemes')]
    public function testRejectsNonSmtpSchemes(string $dsn): void
    {
        $validator = new DsnValidator(new FakeDnsResolver());

        $this->expectException(DsnRejected::class);
        try {
            $validator->validate($dsn);
        } catch (DsnRejected $dsnRejected) {
            $this->assertSame(DsnRejected::REASON_SCHEME, $dsnRejected->reason);
            throw $dsnRejected;
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejectedHosts(): iterable
    {
        yield 'loopback v4' => ['127.0.0.1', '127.0.0.1'];
        yield 'rfc1918 10/8' => ['10.0.0.5', '10.0.0.5'];
        yield 'rfc1918 192.168' => ['192.168.1.10', '192.168.1.10'];
        yield 'rfc1918 172.16' => ['172.16.0.1', '172.16.0.1'];
        yield 'link-local v4' => ['169.254.1.1', '169.254.1.1'];
        yield 'multicast v4' => ['224.0.0.1', '224.0.0.1'];
        yield 'unspecified v4' => ['0.0.0.0', '0.0.0.0'];
        yield 'loopback v6' => ['::1', '::1'];
        yield 'ula v6' => ['fc00::1', 'fc00::1'];
        yield 'link-local v6' => ['fe80::1', 'fe80::1'];
    }

    #[DataProvider('rejectedHosts')]
    public function testRejectsPrivateOrReservedHosts(string $ip, string $expectedRejection): void
    {
        $dns = new FakeDnsResolver();
        $dns->setMapping('attacker.example', [$ip]);

        $validator = new DsnValidator($dns);

        $this->expectException(DsnRejected::class);
        try {
            $validator->validate('smtp://x@attacker.example:25');
        } catch (DsnRejected $dsnRejected) {
            $this->assertSame(DsnRejected::REASON_HOST, $dsnRejected->reason);
            $this->assertStringContainsString($expectedRejection, $dsnRejected->getMessage());
            throw $dsnRejected;
        }
    }

    public function testRejectsMixedPublicAndPrivate(): void
    {
        $dns = new FakeDnsResolver();
        $dns->setMapping('split-horizon.example', ['93.184.216.34', '10.0.0.1']);

        $validator = new DsnValidator($dns);

        $this->expectException(DsnRejected::class);
        $validator->validate('smtp://x@split-horizon.example:25');
    }

    public function testRejectsUnresolvable(): void
    {
        $dns = new FakeDnsResolver();
        $validator = new DsnValidator($dns);

        $this->expectException(DsnRejected::class);
        try {
            $validator->validate('smtp://x@nx.example:25');
        } catch (DsnRejected $dsnRejected) {
            $this->assertSame(DsnRejected::REASON_UNRESOLVABLE, $dsnRejected->reason);
            throw $dsnRejected;
        }
    }

    public function testRejectsHostlessDsn(): void
    {
        $validator = new DsnValidator(new FakeDnsResolver());

        $this->expectException(DsnRejected::class);
        $validator->validate('smtp://');
    }
}
