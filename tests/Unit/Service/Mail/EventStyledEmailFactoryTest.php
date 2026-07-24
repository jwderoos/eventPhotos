<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mail;

use App\Entity\Event;
use App\Entity\EventNotificationSubscription;
use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Service\Mail\EncryptedDsn;
use App\Service\Mail\EventStyledEmailFactory;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class EventStyledEmailFactoryTest extends TestCase
{
    public function testConfirmationEmailShape(): void
    {
        $factory = new EventStyledEmailFactory($this->urlGenerator());
        [$event, $sub, $config] = $this->fixtures();

        $email = $factory->confirmation($event, $sub, $config);

        $this->assertSame('Confirm notifications for Sample Event', $email->getSubject());
        $this->assertSame('email/event-notification/confirm.html.twig', $email->getHtmlTemplate());
        $this->assertSame('email/event-notification/confirm.txt.twig', $email->getTextTemplate());
        $this->assertSame('press@example.test', $email->getFrom()[0]->getAddress());
        $this->assertSame('visitor@example.com', $email->getTo()[0]->getAddress());

        $context = $email->getContext();
        $this->assertSame('Sample Event', $context['eventName']);
        $this->assertArrayHasKey('confirmUrl', $context);
        $this->assertArrayHasKey('unsubscribeUrl', $context);
    }

    public function testLiveAnnouncementEmailShape(): void
    {
        $factory = new EventStyledEmailFactory($this->urlGenerator());
        [$event, $sub, $config] = $this->fixtures();

        $email = $factory->liveAnnouncement($event, $sub, $config);

        $this->assertSame('Photos from Sample Event are live', $email->getSubject());
        $this->assertSame('email/event-notification/live.html.twig', $email->getHtmlTemplate());
        $this->assertSame('email/event-notification/live.txt.twig', $email->getTextTemplate());

        $context = $email->getContext();
        $this->assertArrayHasKey('eventUrl', $context);
        $this->assertArrayHasKey('unsubscribeUrl', $context);
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        $gen = $this->createStub(UrlGeneratorInterface::class);
        $gen->method('generate')->willReturn('https://example.test/url');

        return $gen;
    }

    /** @return array{Event, EventNotificationSubscription, UserMailConfig} */
    private function fixtures(): array
    {
        $owner = new User('owner@example.test', 'Owner');
        $event = new Event(
            slug: 'sample-event',
            name: 'Sample Event',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: $owner,
        );
        $sub = new EventNotificationSubscription(
            $event,
            'visitor@example.com',
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        $config = new UserMailConfig(
            $owner,
            new EncryptedDsn('ciphertext', 'nonce'),
            'press@example.test',
            null,
        );

        return [$event, $sub, $config];
    }
}
