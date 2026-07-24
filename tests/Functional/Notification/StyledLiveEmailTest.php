<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Entity\Event;
use App\Entity\EventNotificationSubscription;
use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Message\SendEventLiveEmail;
use App\MessageHandler\SendEventLiveEmailHandler;
use App\Service\Mail\DsnVault;
use App\Tests\Mail\CapturedMail;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

final class StyledLiveEmailTest extends KernelTestCase
{
    /** DSN host smtp.example-organizer.test resolves here via the test DNS stub. */
    private const string ORGANIZER_MAIL_HOST = '93.184.216.34';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em = $em;
        CapturedMail::reset();
    }

    /** @return array{0: EventNotificationSubscription} */
    private function seed(string $slug, bool $withBanner): array
    {
        $owner = new User($slug . '-owner@example.com', 'Owner');
        $owner->addRole('ROLE_ORGANIZER');

        $this->em->persist($owner);

        /** @var DsnVault $vault */
        $vault = self::getContainer()->get(DsnVault::class);
        $config = new UserMailConfig(
            $owner,
            $vault->encrypt('smtp://x@smtp.example-organizer.test:25'),
            $slug . '-owner@example.com',
            null,
        );
        $config->markVerified();

        $this->em->persist($config);

        $event = new Event(
            slug: $slug,
            name: 'Styled Live',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: $owner,
        );
        $event->getStyle()->setBackgroundColor('#123456');
        $event->getStyle()->setButtonColor('#abcdef');

        if ($withBanner) {
            /** @var FilesystemOperator $banners */
            $banners = self::getContainer()->get('event_banners_storage');
            $filename = 'event-' . $slug . '.jpg';
            $banners->write($filename, (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/photos/bigger.jpg'));
            $event->setBannerFilename($filename);
        }

        $this->em->persist($event);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $sub = new EventNotificationSubscription($event, 'visitor@example.com', $now);
        $sub->confirm($now);

        $this->em->persist($sub);
        $this->em->flush();

        return [$sub];
    }

    private function invoke(EventNotificationSubscription $sub): Email
    {
        /** @var SendEventLiveEmailHandler $handler */
        $handler = self::getContainer()->get(SendEventLiveEmailHandler::class);
        $id = $sub->getId();
        $this->assertNotNull($id);
        $handler(new SendEventLiveEmail($id));

        $messages = CapturedMail::messagesForHost(self::ORGANIZER_MAIL_HOST);
        $this->assertCount(1, $messages);
        $email = $messages[0];
        $this->assertInstanceOf(Email::class, $email);

        return $email;
    }

    public function testLiveEmailIsStyledAndEmbedsHero(): void
    {
        [$sub] = $this->seed('styled-live-hero', true);

        $email = $this->invoke($sub);
        $html = (string) $email->getHtmlBody();

        $this->assertStringContainsString('#123456', $html);      // background color applied
        $this->assertStringContainsString('#abcdef', $html);      // button color applied
        $this->assertStringContainsString('cid:', $html);         // hero referenced by CID
        $this->assertCount(1, $email->getAttachments());          // inline hero attached
        $this->assertStringContainsString('Unsubscribe', (string) $email->getTextBody()); // txt intact
    }

    public function testLiveEmailWithoutBannerHasNoHero(): void
    {
        [$sub] = $this->seed('styled-live-nohero', false);

        $email = $this->invoke($sub);
        $html = (string) $email->getHtmlBody();

        $this->assertStringContainsString('#123456', $html);
        $this->assertStringNotContainsString('cid:', $html);
        $this->assertCount(0, $email->getAttachments());
    }
}
