<?php

declare(strict_types=1);

namespace App\Service\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\BodyRendererInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Mailer that renders templated emails (Twig BodyRenderer) before handing off
 * to the underlying transport. Used by {@see TransportBuilder} to issue
 * per-DSN mailers that bypass the global mailer pipeline (no MessageBus, no
 * data collector, no global dispatcher) yet still resolve TemplatedEmail
 * templates.
 */
final readonly class RenderingMailer implements MailerInterface
{
    public function __construct(
        private TransportInterface $transport,
        private BodyRendererInterface $bodyRenderer,
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if ($message instanceof Email) {
            $this->bodyRenderer->render($message);
        }

        $this->transport->send($message, $envelope);
    }
}
