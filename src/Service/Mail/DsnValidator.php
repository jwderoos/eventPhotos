<?php

declare(strict_types=1);

namespace App\Service\Mail;

use SensitiveParameter;
use Symfony\Component\Mailer\Exception\InvalidArgumentException as MailerDsnException;
use Symfony\Component\Mailer\Transport\Dsn;

final readonly class DsnValidator
{
    /** @var list<string> */
    private const array ALLOWED_SCHEMES = ['smtp', 'smtps'];

    /** First IPv4 octet of the 224.0.0.0/4 multicast block (also covers 240.0.0.0/4 reserved). */
    private const int IPV4_MULTICAST_FIRST_OCTET = 224;

    /** IPv6 multicast prefix ff00::/8 — packed first byte equals 0xFF. */
    private const int IPV6_MULTICAST_FIRST_BYTE = 0xFF;

    public function __construct(private DnsResolver $dns)
    {
    }

    public function validate(#[SensitiveParameter] string $dsn): void
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
            if (!$this->isPublicIp($ip)) {
                throw DsnRejected::host($host, $ip);
            }
        }
    }

    private function isPublicIp(string $ip): bool
    {
        if (
            filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false
        ) {
            return false;
        }

        // PHP's NO_RES_RANGE misses multicast (IPv4 224.0.0.0/4) and IPv6 multicast (ff00::/8).
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $firstOctet = (int) explode('.', $ip)[0];

            return $firstOctet < self::IPV4_MULTICAST_FIRST_OCTET;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        return \ord($packed[0]) !== self::IPV6_MULTICAST_FIRST_BYTE;
    }
}
