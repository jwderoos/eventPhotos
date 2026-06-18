<?php

declare(strict_types=1);

namespace App\Tests\Mail;

use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

final class InMemoryTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        return new InMemoryTransport($dsn->getHost());
    }

    /** @return list<string> */
    protected function getSupportedSchemes(): array
    {
        return ['smtp', 'smtps'];
    }
}
