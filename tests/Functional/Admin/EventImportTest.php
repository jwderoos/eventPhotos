<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\User;
use App\Service\Event\EventArchiveExporter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EventImportTest extends WebTestCase
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

    public function testOrganizerImportsUnderThemselves(): void
    {
        $owner = $this->makeOrganizer('imp-owner@example.com');
        $zip   = $this->buildArchive('imported-slug', $owner);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/events/import');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Import')->form();
        /** @phpstan-ignore-next-line method.nonObject */
        $form['event_import[archive]']->upload($zip);
        $this->client->submit($form);

        self::assertResponseRedirects();
        $imported = $this->em->getRepository(Event::class)->findOneBy(['slug' => 'imported-slug']);
        $this->assertInstanceOf(Event::class, $imported);
        $this->assertSame($owner->getId(), $imported->getOwner()->getId());
    }

    public function testCollidingSlugIsRefusedAndCreatesNothing(): void
    {
        $owner = $this->makeOrganizer('imp-collide@example.com');
        $this->buildEvent('dupe-slug', $owner); // already exists
        $zip = $this->buildArchive('dupe-slug', $owner, persist: false);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/events/import');
        $form    = $crawler->selectButton('Import')->form();
        /** @phpstan-ignore-next-line method.nonObject */
        $form['event_import[archive]']->upload($zip);
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->assertSame(1, $this->em->getRepository(Event::class)->count(['slug' => 'dupe-slug']));
    }

    public function testAnonymousIsDenied(): void
    {
        $this->client->request(Request::METHOD_GET, '/admin/events/import');
        self::assertResponseStatusCodeSame(302); // redirected to login
    }

    private function buildArchive(string $slug, User $owner, bool $persist = true): string
    {
        $event = $this->buildEvent($slug, $owner, $persist);
        /** @var EventArchiveExporter $exporter */
        $exporter = self::getContainer()->get(EventArchiveExporter::class);
        $zip      = $exporter->export($event);

        if ($persist) {
            // Free the slug so the archive can be imported back.
            $event->setSlug($slug . '-archived');
            $this->em->flush();
        } else {
            $this->em->remove($event);
            $this->em->flush();
        }

        return $zip;
    }

    private function buildEvent(string $slug, User $owner, bool $persist = true): Event
    {
        $event = new Event(
            $slug,
            'Event ' . $slug,
            new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-01-01 12:00:00', new DateTimeZone('UTC')),
            $owner,
        );

        if ($persist) {
            $this->em->persist($event);
            $this->em->flush();
        }

        return $event;
    }

    private function makeOrganizer(string $email): User
    {
        $user = new User($email, 'Owner');
        $user->addRole('ROLE_ORGANIZER');

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
