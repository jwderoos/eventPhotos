<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Tests\Support\PhotoFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EventSearchTest extends WebTestCase
{
    public function testColourFilterNarrowsGallery(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $event  = PhotoFixtures::event($em, slug: 'run-2026');
        $orange = PhotoFixtures::readyPhoto($em, $event, '2026-07-15 10:00:00');
        $blue   = PhotoFixtures::readyPhoto($em, $event, '2026-07-15 10:01:00');
        PhotoFixtures::tagColour($em, $orange, 'orange');
        PhotoFixtures::tagColour($em, $blue, 'blue');
        $em->flush();

        $crawler = $client->request(Request::METHOD_GET, '/e/run-2026/photos?colour%5B%5D=orange');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[data-lightbox-target="trigger"]'));
    }

    public function testBibFilterIgnoredWhenToggleOff(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $event = PhotoFixtures::event($em, slug: 'nobib-2026');
        $photo = PhotoFixtures::readyPhoto($em, $event, '2026-07-15 10:00:00');
        PhotoFixtures::tagBib($em, $photo, '1423');
        $em->flush();

        // bib param present but toggle off → treated as no filter → full windowed gallery,
        // and the bib field must NOT be rendered (no leak that bibs are indexed).
        // t=12:00 keeps the windowed path in-window (event window is 09:00-18:00),
        // otherwise the no-filter windowed branch would 302-redirect on a bare request.
        $crawler = $client->request(Request::METHOD_GET, '/e/nobib-2026/photos?bib=1423&t=12:00');

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('[data-testid="bib-filter"]'));
    }

    public function testBibFilterMatchesWhenToggleOn(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $event = PhotoFixtures::event($em, slug: 'bib-2026', bibIndexing: true);
        $hit   = PhotoFixtures::readyPhoto($em, $event, '2026-07-15 10:00:00');
        $miss  = PhotoFixtures::readyPhoto($em, $event, '2026-07-15 10:01:00');
        PhotoFixtures::tagBib($em, $hit, '1423');
        PhotoFixtures::tagBib($em, $miss, '2000');
        $em->flush();

        $crawler = $client->request(Request::METHOD_GET, '/e/bib-2026/photos?bib=1423');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[data-lightbox-target="trigger"]'));
        $this->assertCount(1, $crawler->filter('[data-testid="bib-filter"]'));
    }
}
