<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\PhotoAttribute;
use App\Entity\PhotoAttributeType;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PhotoTagOverviewTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private UserPasswordHasherInterface $hasher;

    private User $owner;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $c = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);

        $this->em = $em;
        $this->hasher = $hasher;

        $this->owner = $this->makeUser('o@example.test');
        $this->client->loginUser($this->owner);
    }

    private function makeUser(string $email): User
    {
        $user = new User($email, 'U');
        $user->setPassword($this->hasher->hashPassword($user, 'x'));
        $user->addRole('ROLE_ORGANIZER');

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function makeEvent(User $owner): Event
    {
        $event = new Event(
            'e' . bin2hex(random_bytes(4)),
            'E',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $event->setTimezone('UTC');

        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    private function addReadyWithTags(Event $event, string $hashSeed, PhotoAttributeType $type, string $value): Photo
    {
        $photo = new Photo($event, str_pad($hashSeed, 64, '0'), $hashSeed . '.jpg', 100);
        $photo->markReady(new DateTimeImmutable('now', new DateTimeZone('UTC')), 100, 100, 2048);

        $this->em->persist($photo);
        $this->em->persist(new PhotoAttribute($photo, $type, $value, 0.94));
        $this->em->flush();

        return $photo;
    }

    public function testOverviewShowsAggregatedCountsWithLinksExceptScene(): void
    {
        $event = $this->makeEvent($this->owner);
        // Two photos tagged red → count 2; one bib; one scene.
        $this->addReadyWithTags($event, 'aa', PhotoAttributeType::ClothingColor, 'red');
        $this->addReadyWithTags($event, 'bb', PhotoAttributeType::ClothingColor, 'red');
        $this->addReadyWithTags($event, 'cc', PhotoAttributeType::Bib, '142');
        $this->addReadyWithTags($event, 'dd', PhotoAttributeType::Scene, 'sunset');

        $this->client->request(Request::METHOD_GET, sprintf('/admin/events/%d/tags', (int) $event->getId()));

        self::assertResponseIsSuccessful();

        // Colour: aggregated count of 2, linked to the public gallery colour filter.
        $colourLink = $this->client->getCrawler()->filter('a[data-role="tag-chip"][href*="colour"]');
        $this->assertGreaterThan(0, $colourLink->count(), 'Colour tag must link to the public gallery filter.');
        $this->assertStringContainsString('red', $colourLink->text());
        $this->assertStringContainsString('2', $colourLink->text());

        // Bib: linked with bib= param.
        self::assertSelectorExists('a[data-role="tag-chip"][href*="bib=142"]');

        // Scene: rendered but NOT linked (public gallery has no scene filter).
        self::assertSelectorTextContains('[data-role="tag-chip-plain"]', 'sunset');
        self::assertSelectorNotExists('a[data-role="tag-chip"][href*="sunset"]');
    }

    public function testOverviewLinkedFromManagePage(): void
    {
        $event = $this->makeEvent($this->owner);

        $this->client->request(Request::METHOD_GET, sprintf('/admin/events/%d/photos', (int) $event->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('a[href$="/events/%d/tags"]', (int) $event->getId()));
    }

    public function testOverviewDeniedForNonOwner(): void
    {
        $event = $this->makeEvent($this->owner);

        $stranger = $this->makeUser('stranger@example.test');
        $this->client->loginUser($stranger);

        $this->client->request(Request::METHOD_GET, sprintf('/admin/events/%d/tags', (int) $event->getId()));

        self::assertResponseStatusCodeSame(403);
    }
}
