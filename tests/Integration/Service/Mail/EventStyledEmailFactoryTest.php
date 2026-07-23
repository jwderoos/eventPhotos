<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Mail;

use App\Entity\Event;
use App\Entity\User;
use App\Service\Mail\EventStyledEmailFactory;
use App\Service\Style\ResolvedStyle;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EventStyledEmailFactoryTest extends KernelTestCase
{
    private const string HTML = 'email/event-notification/live.html.twig';

    private const string TXT = 'email/event-notification/live.txt.twig';

    private EntityManagerInterface $em;

    private FilesystemOperator $banners;

    private EventStyledEmailFactory $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var FilesystemOperator $banners */
        $banners = $c->get('event_banners_storage');
        /** @var EventStyledEmailFactory $factory */
        $factory = $c->get(EventStyledEmailFactory::class);
        $this->em = $em;
        $this->banners = $banners;
        $this->factory = $factory;
    }

    private function persistEvent(string $slug): Event
    {
        $owner = new User($slug . '-owner@example.com', 'Owner');
        $event = new Event(
            slug: $slug,
            name: 'Styled Event',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: $owner,
        );
        $event->getStyle()->setBackgroundColor('#123456');
        $event->getStyle()->setButtonColor('#abcdef');

        $this->em->persist($owner);
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    public function testResolvesStyleAndEmbedsHeroWhenBannerPresent(): void
    {
        $event = $this->persistEvent('styled-with-banner');
        $filename = 'event-styled-with-banner.jpg';
        $fixturePath = dirname(__DIR__, 3) . '/fixtures/photos/bigger.jpg';
        $this->banners->write($filename, (string) file_get_contents($fixturePath));
        $event->setBannerFilename($filename);
        $this->em->flush();

        $email = $this->factory->create($event, self::HTML, self::TXT, ['eventName' => $event->getName()]);

        $context = $email->getContext();
        $this->assertInstanceOf(ResolvedStyle::class, $context['style']);
        $this->assertSame('#123456', $context['style']->backgroundColor);
        $this->assertArrayHasKey('heroCid', $context);
        $this->assertNotSame('', $context['heroCid']);
        $this->assertCount(1, $email->getAttachments());
    }

    public function testNoHeroWhenBannerAbsent(): void
    {
        $event = $this->persistEvent('styled-no-banner');

        $email = $this->factory->create($event, self::HTML, self::TXT, ['eventName' => $event->getName()]);

        $this->assertArrayNotHasKey('heroCid', $email->getContext());
        $this->assertCount(0, $email->getAttachments());
    }

    public function testMissingBannerFileIsSwallowed(): void
    {
        $event = $this->persistEvent('styled-broken-banner');
        // Never written to storage.
        $event->setBannerFilename('event-does-not-exist.jpg');

        $this->em->flush();

        $email = $this->factory->create($event, self::HTML, self::TXT, ['eventName' => $event->getName()]);

        $this->assertArrayNotHasKey('heroCid', $email->getContext());
        $this->assertCount(0, $email->getAttachments());
    }
}
