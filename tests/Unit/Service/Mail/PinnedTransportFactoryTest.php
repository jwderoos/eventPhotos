<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mail;

use PHPUnit\Framework\Attributes\DataProvider;
use App\Service\Mail\DsnRejected;
use App\Service\Mail\PinnedTransportFactory;
use App\Service\Mail\PublicIpInspector;
use App\Tests\Fake\FakeDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

final class PinnedTransportFactoryTest extends TestCase
{
    private function factory(FakeDnsResolver $dns): PinnedTransportFactory
    {
        return new PinnedTransportFactory(
            $dns,
            new PublicIpInspector(),
            new Transport([new EsmtpTransportFactory()]),
        );
    }

    public function testPinsToValidatedIpv4AndPreservesHostnameForTls(): void
    {
        $dns = new FakeDnsResolver();
        $dns->setMapping('smtp.example.com', ['93.184.216.34']);

        $transport = $this->factory($dns)->create('smtp://user:pass@smtp.example.com:587');

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertSame('93.184.216.34', $stream->getHost());
        $this->assertSame(587, $stream->getPort());
        $sslOptions = $stream->getStreamOptions()['ssl'] ?? null;
        $this->assertIsArray($sslOptions);
        $this->assertSame('smtp.example.com', $sslOptions['peer_name'] ?? null);
    }

    public function testPrefersIpv4WhenHostIsDualStack(): void
    {
        $dns = new FakeDnsResolver();
        $dns->setMapping('mail.example.com', ['2606:4700:4700::1111', '93.184.216.34']);

        $transport = $this->factory($dns)->create('smtps://u:p@mail.example.com:465');

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertSame('93.184.216.34', $stream->getHost());
    }

    public function testBracketsLiteralIpv6Host(): void
    {
        $dns = new FakeDnsResolver();
        $dns->setMapping('v6.example.com', ['2606:4700:4700::1111']);

        $transport = $this->factory($dns)->create('smtps://u:p@v6.example.com:465');

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertSame('[2606:4700:4700::1111]', $stream->getHost());
    }

    public function testQueryOptionsAreForwardedToPinnedTransport(): void
    {
        $dns = new FakeDnsResolver();
        $dns->setMapping('smtp.example.com', ['93.184.216.34']);

        $transport = $this->factory($dns)->create('smtp://user:pass@smtp.example.com:587?local_domain=example.org');

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame('example.org', $transport->getLocalDomain());
    }

    /** @return iterable<string, array{string}> */
    public static function reboundIps(): iterable
    {
        yield 'loopback (rebind)' => ['127.0.0.1'];
        yield 'metadata mapped v6' => ['::ffff:169.254.169.254'];
        yield 'cgnat' => ['100.64.0.1'];
    }

    #[DataProvider('reboundIps')]
    public function testRejectsHostResolvingToNonPublicIp(string $ip): void
    {
        $dns = new FakeDnsResolver();
        $dns->setMapping('evil.example.com', [$ip]);

        $this->expectException(DsnRejected::class);
        try {
            $this->factory($dns)->create('smtp://u:p@evil.example.com:25');
        } catch (DsnRejected $dsnRejected) {
            $this->assertSame(DsnRejected::REASON_HOST, $dsnRejected->reason);
            throw $dsnRejected;
        }
    }

    public function testRejectsUnresolvableHost(): void
    {
        $this->expectException(DsnRejected::class);
        try {
            $this->factory(new FakeDnsResolver())->create('smtp://u:p@nope.example.com:25');
        } catch (DsnRejected $dsnRejected) {
            $this->assertSame(DsnRejected::REASON_UNRESOLVABLE, $dsnRejected->reason);
            throw $dsnRejected;
        }
    }

    public function testRejectsNonSmtpScheme(): void
    {
        $this->expectException(DsnRejected::class);
        try {
            $this->factory(new FakeDnsResolver())->create('sendgrid+api://KEY@default');
        } catch (DsnRejected $dsnRejected) {
            $this->assertSame(DsnRejected::REASON_SCHEME, $dsnRejected->reason);
            throw $dsnRejected;
        }
    }
}
