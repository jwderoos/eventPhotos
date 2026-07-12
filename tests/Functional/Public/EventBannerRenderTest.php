<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EventBannerRenderTest extends WebTestCase
{
    private function persist(EntityManagerInterface $em, string $slug, bool $withBanner): void
    {
        $owner = new User($slug . '@example.com', 'Owner');
        $owner->setPassword('x');

        $event = new Event(
            $slug,
            'Hero Render',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );

        if ($withBanner) {
            $event->setBannerFilename('event-render.jpg');
            $event->setBannerUpdatedAt(new DateTimeImmutable('2026-07-07 12:00'));
        }

        $em->persist($owner);
        $em->persist($event);
        $em->flush();
    }

    public function testHeroImageRendersWhenBannerSet(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->persist($em, 'hero-yes', true);

        $crawler = $client->request(Request::METHOD_GET, '/e/hero-yes');

        self::assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('img[src$="/banner.jpg"]')->count());
    }

    public function testNoHeroImageWhenBannerAbsent(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->persist($em, 'hero-no', false);

        $crawler = $client->request(Request::METHOD_GET, '/e/hero-no');

        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('img[src$="/banner.jpg"]'));
    }
}
