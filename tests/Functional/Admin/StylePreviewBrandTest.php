<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\OrganizerProfile;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class StylePreviewBrandTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em = $em;
    }

    /** @param list<string> $roles */
    private function seedUser(string $email, array $roles): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User($email, 'Seeded');
        foreach ($roles as $role) {
            $user->addRole($role);
        }

        $user->setPassword($hasher->hashPassword($user, 'placeholder placeholder'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function brandFor(User $owner): void
    {
        $profile = new OrganizerProfile($owner);
        $profile->setBrandLabel('Acme Corp');
        $profile->setBrandLogoFilename('acme.png');
        $profile->setBrandUrl('https://acme.example');

        $this->em->persist($profile);
        $this->em->flush();
    }

    public function testNewEventFormShowsOwnBrandHeaderInPreview(): void
    {
        $organizer = $this->seedUser('org-brand@example.com', ['ROLE_ORGANIZER']);
        $this->brandFor($organizer);
        $this->client->loginUser($organizer);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/events/new');
        self::assertResponseIsSuccessful();

        $card = $crawler->filter('[data-style-preview-target="card"]');
        $this->assertStringContainsString('Acme Corp', $card->html());
        $this->assertStringContainsString('powered by: EventPhotos by JWdR', $card->html());
        $this->assertGreaterThan(
            0,
            $card->filter('img[src="/account/brand-logo"]')->count(),
            'own-brand logo img not found in preview card',
        );
    }

    public function testNewEventFormShowsDefaultHeaderWhenNoBrand(): void
    {
        $organizer = $this->seedUser('org-nobrand@example.com', ['ROLE_ORGANIZER']);
        $this->client->loginUser($organizer);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/events/new');
        self::assertResponseIsSuccessful();

        $card = $crawler->filter('[data-style-preview-target="card"]');
        $this->assertStringContainsString('EventPhotos by JWdR', $card->html());
        $this->assertStringNotContainsString('powered by: EventPhotos by JWdR', $card->html());
        $this->assertCount(0, $card->filter('img'), 'no brand img expected without a brand');
    }

    public function testAdminEditingAnotherOwnersEventShowsOwnerBrandViaAdminRoute(): void
    {
        $admin = $this->seedUser('admin-preview@example.com', ['ROLE_ADMIN']);
        $owner = $this->seedUser('owner-preview@example.com', ['ROLE_ORGANIZER']);
        $this->brandFor($owner);

        $event = new Event(
            'preview-owner-slug',
            'Owned Event',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );
        $this->em->persist($event);
        $this->em->flush();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/events/' . $event->getId() . '/edit');
        self::assertResponseIsSuccessful();

        $card = $crawler->filter('[data-style-preview-target="card"]');
        $this->assertStringContainsString('Acme Corp', $card->html());
        $this->assertGreaterThan(
            0,
            $card->filter('img[src="/admin/users/' . $owner->getId() . '/brand-logo"]')->count(),
            'owner-brand logo img (admin route) not found in preview card',
        );
    }
}
