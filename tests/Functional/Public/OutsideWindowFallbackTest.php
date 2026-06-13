<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Covers issue #59: outside the event window, the landing CTA and the
 * photos route fall back to the event start time instead of erroring.
 */
final class OutsideWindowFallbackTest extends WebTestCase
{
    public function testLandingInsideWindowEncodesNow(): void
    {
        $client = self::createClient();
        $this->seedSummerFest('inside-owner@example.test');

        /** @var MockClock $clock */
        $clock = self::getContainer()->get(ClockInterface::class);
        // 12:30 Europe/Amsterdam in July (UTC+2) = 10:30 UTC; inside the 10:00-14:00 window.
        $clock->modify('2026-07-15 10:30:00');

        $crawler = $client->request(Request::METHOD_GET, '/e/summer-fest');

        $this->assertResponseIsSuccessful();
        $this->assertSame(
            '/e/summer-fest/photos?t=12:30',
            $this->firstAttr($crawler, 'a.btn-primary', 'href'),
        );
    }

    public function testLandingBeforeWindowEncodesEventStart(): void
    {
        $client = self::createClient();
        $this->seedSummerFest('before-owner@example.test');

        /** @var MockClock $clock */
        $clock = self::getContainer()->get(ClockInterface::class);
        // 09:00 Europe/Amsterdam = 07:00 UTC, an hour before the event begins.
        $clock->modify('2026-07-15 07:00:00');

        $crawler = $client->request(Request::METHOD_GET, '/e/summer-fest');

        $this->assertResponseIsSuccessful();
        $this->assertSame(
            '/e/summer-fest/photos?t=10:00',
            $this->firstAttr($crawler, 'a.btn-primary', 'href'),
        );
    }

    public function testLandingAfterWindowEncodesEventStart(): void
    {
        $client = self::createClient();
        $this->seedSummerFest('after-owner@example.test');

        /** @var MockClock $clock */
        $clock = self::getContainer()->get(ClockInterface::class);
        // 16:00 Europe/Amsterdam = 14:00 UTC, two hours after the event ends.
        $clock->modify('2026-07-15 14:00:00');

        $crawler = $client->request(Request::METHOD_GET, '/e/summer-fest');

        $this->assertResponseIsSuccessful();
        $this->assertSame(
            '/e/summer-fest/photos?t=10:00',
            $this->firstAttr($crawler, 'a.btn-primary', 'href'),
        );
    }

    public function testPhotosWithOutsideWindowTimeRedirectsToEventStart(): void
    {
        $client = self::createClient();
        $this->seedSummerFest('redirect-owner@example.test');

        $client->request(Request::METHOD_GET, '/e/summer-fest/photos?t=09:00');

        $this->assertResponseStatusCodeSame(Response::HTTP_FOUND);
        $this->assertResponseRedirects('/e/summer-fest/photos?t=10:00');
    }

    public function testPhotosWithInsideWindowTimeStillRenders(): void
    {
        $client = self::createClient();
        $this->seedSummerFest('inside-photos-owner@example.test');

        $client->request(Request::METHOD_GET, '/e/summer-fest/photos?t=12:00');

        $this->assertResponseIsSuccessful();
    }

    private function seedSummerFest(string $ownerEmail): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User($ownerEmail, 'Owner');
        $owner->setPassword('x');

        $em->persist($owner);

        $tz       = new DateTimeZone('Europe/Amsterdam');
        $startsAt = new DateTimeImmutable('2026-07-15 10:00', $tz);
        $endsAt   = new DateTimeImmutable('2026-07-15 14:00', $tz);

        $event = new Event('summer-fest', 'Summer Fest', $startsAt, $endsAt, $owner);
        $event->setTimezone('Europe/Amsterdam');

        $em->persist($event);
        $em->flush();
    }

    private function firstAttr(Crawler $crawler, string $selector, string $attr): string
    {
        $node = $crawler->filter($selector)->first();
        $this->assertGreaterThan(0, $node->count(), sprintf('Selector "%s" not found', $selector));

        return (string) $node->attr($attr);
    }
}
