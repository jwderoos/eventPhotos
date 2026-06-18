<?php

declare(strict_types=1);

namespace App\Tests\Integration\Mail;

use App\Tests\Mail\CapturedMail;
use App\Tests\Mail\InMemoryTransport;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

final class InMemoryTransportSmokeTest extends KernelTestCase
{
    private Transport $containerTransportFactory;

    protected function setUp(): void
    {
        self::bootKernel();
        // The container-built Transport (id: mailer.transport_factory) is the one that
        // sees the tagged InMemoryTransportFactory. The static Transport::fromDsn does NOT.
        /** @var Transport $svc */
        $svc = self::getContainer()->get('mailer.transport_factory');
        $this->containerTransportFactory = $svc;
        CapturedMail::reset();
    }

    public function testStaticFromDsnDoesNotUseInMemoryTransport(): void
    {
        // Documenting the wrinkle: static factory chain does not consult container tags.
        $transport = Transport::fromDsn('smtp://user:pass@smtp.test.example:25');

        $this->assertNotInstanceOf(InMemoryTransport::class, $transport);
    }

    public function testContainerFactoryRoutesSmtpToInMemoryTransport(): void
    {
        $transport = $this->containerTransportFactory->fromString('smtp://user:pass@smtp.test.example:25');

        $this->assertInstanceOf(InMemoryTransport::class, $transport);
    }

    public function testSendingRecordsByHost(): void
    {
        $transport = $this->containerTransportFactory->fromString('smtp://x@org.test:25');
        $mailer = new Mailer($transport);
        $email = new Email()
            ->from('a@org.test')
            ->to('b@org.test')
            ->subject('hi')
            ->text('body');

        $mailer->send($email);

        $this->assertCount(1, CapturedMail::messagesForHost('org.test'));
    }

    public function testThrowOnHost(): void
    {
        CapturedMail::throwOnHost('boom.test', new TransportException('configured failure'));
        $transport = $this->containerTransportFactory->fromString('smtp://x@boom.test:25');
        $mailer = new Mailer($transport);
        $email = new Email()
            ->from('a@boom.test')
            ->to('b@boom.test')
            ->subject('hi')
            ->text('body');

        $this->expectException(TransportException::class);
        $mailer->send($email);
    }
}
