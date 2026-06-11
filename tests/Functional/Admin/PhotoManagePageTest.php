<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PhotoManagePageTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Event $event;

    private User $owner;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $c            = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);

        $this->em = $em;

        $this->owner = new User('owner@example.test', 'Owner');
        $this->owner->setPassword($hasher->hashPassword($this->owner, 'secret'));
        $this->owner->addRole('ROLE_ORGANIZER');

        $this->em->persist($this->owner);

        $this->event = new Event('demo', 'Demo', new DateTimeImmutable('2026-06-10'), $this->owner);
        $this->event->setTimezone('Europe/Amsterdam');

        $this->em->persist($this->event);
        $this->em->flush();
    }

    public function testManagePageRendersForOwner(): void
    {
        $this->client->loginUser($this->owner);

        $url     = sprintf('/admin/events/%d/photos', (int) $this->event->getId());
        $crawler = $this->client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('[data-controller="photo-uploader"]')->count());
        $this->assertGreaterThan(0, $crawler->filter('turbo-frame#photos-grid')->count());
        $this->assertStringContainsString('Photos', (string) $this->client->getResponse()->getContent());
    }

    public function testManagePageRejectsNonOwner(): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher   = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stranger = new User('stranger@example.test', 'Stranger');
        $stranger->setPassword($hasher->hashPassword($stranger, 'x'));
        $stranger->addRole('ROLE_ORGANIZER');

        $this->em->persist($stranger);
        $this->em->flush();

        $this->client->loginUser($stranger);

        $url = sprintf('/admin/events/%d/photos', (int) $this->event->getId());
        $this->client->request(Request::METHOD_GET, $url);

        self::assertResponseStatusCodeSame(403);
    }

    public function testManagePageRequiresAuthentication(): void
    {
        $url = sprintf('/admin/events/%d/photos', (int) $this->event->getId());
        $this->client->request(Request::METHOD_GET, $url);

        self::assertResponseRedirects('/login');
    }

    public function testEventListShowsPhotosLinkToManagePage(): void
    {
        $this->client->loginUser($this->owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/events');

        self::assertResponseIsSuccessful();
        $expected = sprintf('/admin/events/%d/photos', (int) $this->event->getId());
        $this->assertGreaterThan(
            0,
            $crawler->filter(sprintf('a[href="%s"]', $expected))->count(),
            'Event list row should link to the photo manage page.',
        );
    }
}
