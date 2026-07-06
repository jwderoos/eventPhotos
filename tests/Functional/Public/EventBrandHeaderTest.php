<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\OrganizerProfile;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EventBrandHeaderTest extends WebTestCase
{
    private function makeEvent(EntityManagerInterface $em, string $slug, string $email): Event
    {
        $owner = new User($email, 'Owner');
        $owner->setPassword('x');

        $event = new Event(
            $slug,
            'Branded Event',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );
        $em->persist($owner);
        $em->persist($event);
        $em->flush();

        return $event;
    }

    public function testDefaultPlatformLabelWhenNoBrand(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $event = $this->makeEvent($em, 'brand-default-slug', 'brand-default@example.com');

        $client->request(Request::METHOD_GET, '/e/' . $event->getSlug());
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('EventPhotos by JWdR', $html);
        $this->assertStringContainsString('© ' . date('Y') . ' EventPhotos by JWdR', $html);
        $this->assertStringNotContainsString('powered by: EventPhotos by JWdR', $html);
    }

    public function testBrandedHeaderWithLabelLogoAndUrl(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $event = $this->makeEvent($em, 'brand-full-slug', 'brand-full@example.com');

        $profile = new OrganizerProfile($event->getOwner());
        $profile->setBrandLabel('Acme Corp');
        $profile->setBrandLogoFilename('acme.png');
        $profile->setBrandUrl('https://acme.example');

        $em->persist($profile);
        $em->flush();

        $crawler = $client->request(Request::METHOD_GET, '/e/' . $event->getSlug());
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Acme Corp', $html);
        $this->assertStringContainsString('powered by: EventPhotos by JWdR', $html);

        // Brand is linked to the homepage
        $link = $crawler->filter('header a[href="https://acme.example"]');
        $this->assertGreaterThan(0, $link->count(), 'brand link to homepage not found');
        // Logo points at the public serve route
        $img = $crawler->filter('header img[src$="/brand-logo.png"]');
        $this->assertGreaterThan(0, $img->count(), 'brand logo img not found');
    }

    public function testBrandedHeaderWithoutUrlRendersNoAnchor(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $event = $this->makeEvent($em, 'brand-nourl-slug', 'brand-nourl@example.com');

        $profile = new OrganizerProfile($event->getOwner());
        $profile->setBrandLabel('Acme Corp');
        // no brandUrl
        $em->persist($profile);
        $em->flush();

        $crawler = $client->request(Request::METHOD_GET, '/e/' . $event->getSlug());
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Acme Corp', $html);
        $this->assertStringContainsString('powered by: EventPhotos by JWdR', $html);

        // The brand label must NOT be wrapped in an anchor (no dead link).
        $brandBlock = $crawler->filter('[data-brand-primary]');
        $this->assertGreaterThan(0, $brandBlock->count(), 'brand primary block not found');
        $this->assertCount(0, $brandBlock->filter('a'), 'brand must render without an anchor when URL is empty');
    }
}
