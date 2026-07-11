<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EventExportTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        /** @var EntityManagerInterface $em */
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $this->em = $em;
    }

    public function testOwnerDownloadsZipAttachment(): void
    {
        [$owner, $event] = $this->makeOwnerAndEvent('exp-owner');
        $this->client->loginUser($owner);

        $this->client->request(Request::METHOD_GET, '/admin/events/' . $event->getId() . '/export');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/zip');
        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('event-exp-owner.zip', $disposition);
    }

    public function testNonOwnerIsDenied(): void
    {
        [, $event] = $this->makeOwnerAndEvent('exp-denied');

        $stranger = new User('stranger@example.com', 'Stranger');
        $stranger->addRole('ROLE_ORGANIZER');

        $this->em->persist($stranger);
        $this->em->flush();

        $this->client->loginUser($stranger);

        $this->client->request(Request::METHOD_GET, '/admin/events/' . $event->getId() . '/export');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return array{0: User, 1: Event}
     */
    private function makeOwnerAndEvent(string $slug): array
    {
        $owner = new User($slug . '@example.com', 'Owner');
        $owner->addRole('ROLE_ORGANIZER');

        $this->em->persist($owner);

        $event = new Event(
            $slug,
            'Event',
            new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-01-01 12:00:00', new DateTimeZone('UTC')),
            $owner,
        );
        $this->em->persist($event);
        $this->em->flush();

        return [$owner, $event];
    }
}
