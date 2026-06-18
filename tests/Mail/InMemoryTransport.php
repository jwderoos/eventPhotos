<?php

declare(strict_types=1);

namespace App\Tests\Mail;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

final class InMemoryTransport extends AbstractTransport
{
    public function __construct(private readonly string $host)
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return sprintf('in-memory://%s', $this->host);
    }

    protected function doSend(SentMessage $message): void
    {
        CapturedMail::record($this->host, $message->getOriginalMessage());
    }
}
