<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class EventLandingTest extends WebTestCase
{
    public function testLandingShowsEventNameAndShareControls(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('owner@example.com', 'Owner');
        $owner->setPassword('x');

        $em->persist($owner);
        $em->persist(new Event(
            'summer-fest',
            'Summer Fest',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        ));
        $em->flush();

        $crawler = $client->request(Request::METHOD_GET, '/e/summer-fest');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Summer Fest');
        $this->assertSelectorExists('button[data-action*="share#share"]');

        $href = $this->firstAttr($crawler, 'a.btn-primary', 'href');
        $shareUrl = $this->firstAttr($crawler, 'button[data-action*="share#share"]', 'data-share-url-value');

        foreach ([$href, $shareUrl] as $url) {
            $this->assertMatchesRegularExpression(
                '#/e/summer-fest/photos\?t=\d{2}:\d{2}$#',
                $url,
                'Photos URL must be /e/{slug}/photos?t=HH:mm with no other params',
            );
        }
    }

    public function testLandingReturns404ForUnknownSlug(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('slug-404-test@example.com', 'SlugTest');
        $owner->setPassword('x');

        $em->persist($owner);
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/does-not-exist');

        $this->assertResponseStatusCodeSame(404);
    }

    private function firstAttr(Crawler $crawler, string $selector, string $attr): string
    {
        $node = $crawler->filter($selector)->first();
        $this->assertGreaterThan(0, $node->count(), sprintf('Selector "%s" not found', $selector));

        return (string) $node->attr($attr);
    }
}
