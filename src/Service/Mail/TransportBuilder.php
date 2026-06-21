<?php

declare(strict_types=1);

namespace App\Service\Mail;

use SensitiveParameter;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\BodyRendererInterface;

final readonly class TransportBuilder
{
    public function __construct(
        private PinnedTransportFactory $pinnedTransports,
        private BodyRendererInterface $bodyRenderer,
    ) {
    }

    public function fromDsn(#[SensitiveParameter] string $dsn): MailerInterface
    {
        return new RenderingMailer($this->pinnedTransports->create($dsn), $this->bodyRenderer);
    }
}
