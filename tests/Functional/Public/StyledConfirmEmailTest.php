<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Service\Mail\DsnVault;
use App\Tests\Mail\CapturedMail;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Email;

final class StyledConfirmEmailTest extends WebTestCase
{
    private const string ORGANIZER_MAIL_HOST = '93.184.216.34';

    protected function setUp(): void
    {
        CapturedMail::reset();
    }

    public function testConfirmationEmailIsStyledAndEmbedsHero(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $slug = 'styled-confirm';
        $owner = new User($slug . '-owner@example.com', 'Owner');
        $owner->addRole('ROLE_ORGANIZER');

        $em->persist($owner);

        /** @var DsnVault $vault */
        $vault = self::getContainer()->get(DsnVault::class);
        $config = new UserMailConfig(
            $owner,
            $vault->encrypt('smtp://x@smtp.example-organizer.test:25'),
            $slug . '-owner@example.com',
            null,
        );
        $config->markVerified();

        $em->persist($config);

        $event = new Event(
            slug: $slug,
            name: 'Styled Confirm',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: $owner,
        );
        $event->enableNotifications();
        $event->getStyle()->setBackgroundColor('#123456');
        $event->getStyle()->setButtonColor('#abcdef');

        /** @var FilesystemOperator $banners */
        $banners = self::getContainer()->get('event_banners_storage');
        $filename = 'event-' . $slug . '.jpg';
        $banners->write($filename, (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/photos/bigger.jpg'));
        $event->setBannerFilename($filename);

        $em->persist($event);
        $em->flush();

        $client->request(
            Request::METHOD_POST,
            '/e/' . $slug . '/notify',
            ['email' => 'styled-confirm-visitor@example.com', 'website' => ''],
        );
        self::assertResponseIsSuccessful();

        $messages = CapturedMail::messagesForHost(self::ORGANIZER_MAIL_HOST);
        $this->assertCount(1, $messages);
        $email = $messages[0];
        $this->assertInstanceOf(Email::class, $email);

        $html = (string) $email->getHtmlBody();
        $this->assertStringContainsString('#123456', $html);
        $this->assertStringContainsString('#abcdef', $html);
        $this->assertStringContainsString('cid:', $html);
        $this->assertCount(1, $email->getAttachments());
    }
}
