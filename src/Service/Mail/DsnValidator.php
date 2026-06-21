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

    public function __construct(
        private DnsResolver $dns,
        private PublicIpInspector $inspector,
    ) {
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
            if (!$this->inspector->isPublic($ip)) {
                throw DsnRejected::host($host, $ip);
            }
        }
    }
}
