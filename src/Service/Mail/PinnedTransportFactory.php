<?php

declare(strict_types=1);

namespace App\Service\Mail;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\InvalidArgumentException as MailerDsnException;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Builds an SMTP transport pinned to a validated literal IP, immediately before connect.
 *
 * Resolves the DSN host once, requires every returned IP to be provably public, then
 * connects to the chosen literal IP while passing the original hostname as the TLS
 * peer_name (SNI + cert validation). Nothing re-resolves the hostname afterwards, so the
 * validate-then-reconnect (DNS rebinding) window is closed by construction.
 */
final readonly class PinnedTransportFactory
{
    /** @var list<string> */
    private const array ALLOWED_SCHEMES = ['smtp', 'smtps'];

    public function __construct(
        private DnsResolver $dns,
        private PublicIpInspector $inspector,
        #[Autowire(service: 'mailer.transport_factory')]
        private Transport $transports,
    ) {
    }

    public function create(#[SensitiveParameter] string $dsn): TransportInterface
    {
        try {
            $parsed = Dsn::fromString($dsn);
        } catch (MailerDsnException $mailerDsnException) {
            throw DsnRejected::malformed($mailerDsnException->getMessage());
        }

        $scheme = $parsed->getScheme();
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw DsnRejected::scheme($scheme);
        }

        $host = $parsed->getHost();
        if ($host === '') {
            throw DsnRejected::malformed('host is required');
        }

        $ips = $this->dns->resolve($host);
        if ($ips === []) {
            throw DsnRejected::unresolvable($host);
        }

        foreach ($ips as $ip) {
            if (!$this->inspector->isPublic($ip)) {
                throw DsnRejected::host($host, $ip);
            }
        }

        $pinnedIp = $this->pick($ips);
        $pinnedHost = str_contains($pinnedIp, ':') ? '[' . $pinnedIp . ']' : $pinnedIp;

        $query = parse_url($dsn, PHP_URL_QUERY);
        /** @var array<string, mixed> $options */
        $options = [];
        if (is_string($query) && $query !== '') {
            parse_str($query, $options);
        }

        $transport = $this->transports->fromDsnObject(new Dsn(
            $scheme,
            $pinnedHost,
            $parsed->getUser(),
            $parsed->getPassword(),
            $parsed->getPort(),
            $options,
        ));

        $this->preserveTlsHostname($transport, $host);

        return $transport;
    }

    /**
     * @param non-empty-list<string> $ips
     */
    private function pick(array $ips): string
    {
        foreach ($ips as $ip) {
            if (!str_contains($ip, ':')) {
                return $ip;
            }
        }

        return $ips[0];
    }

    private function preserveTlsHostname(TransportInterface $transport, string $host): void
    {
        if (!$transport instanceof EsmtpTransport) {
            return;
        }

        $stream = $transport->getStream();
        if (!$stream instanceof SocketStream) {
            return;
        }

        $options = $stream->getStreamOptions();
        $ssl = $options['ssl'] ?? [];
        if (!is_array($ssl)) {
            $ssl = [];
        }

        $ssl['peer_name'] = $host;
        $options['ssl'] = $ssl;
        $stream->setStreamOptions($options);
    }
}
