<?php

declare(strict_types=1);

namespace App\Service\Mail;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\BodyRendererInterface;

final readonly class TransportBuilder
{
    public function __construct(
        #[Autowire(service: 'mailer.transport_factory')]
        private Transport $transports,
        private BodyRendererInterface $bodyRenderer,
    ) {
    }

    public function fromDsn(#[SensitiveParameter] string $dsn): MailerInterface
    {
        return new RenderingMailer($this->transports->fromString($dsn), $this->bodyRenderer);
    }
}
