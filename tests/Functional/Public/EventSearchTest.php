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

        $crawler = $client->request(Request::METHOD_GET, '/e/run-2026/photos?q=orange');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[data-lightbox-target="trigger"]'));
        $this->assertCount(1, $crawler->filter('[data-testid="search-chip"]'));
    }

    public function testBibQueryIgnoredWhenToggleOff(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $event = PhotoFixtures::event($em, slug: 'nobib-2026');
        $photo = PhotoFixtures::readyPhoto($em, $event, '2026-07-15 10:00:00');
        PhotoFixtures::tagBib($em, $photo, '1423');
        $em->flush();

        // Bib toggle off → the digits are ignored → no search tokens → falls
        // through to ordinary browse mode (searchMode=false, no chips rendered).
        // Proves the bib was NOT matched into a search token.
        //
        // Deviation from the brief: the brief's version of this test expects
        // assertResponseRedirects() for a bare `?q=1423` (no `t`). That is not
        // reachable: EventController::resolveTimestamp() falls back to "now"
        // whenever `t` is absent, *without* a window check (see
        // EventPhotosStubTest::testMissingTimestampFallsBackToNow and the #59
        // fix in OutsideWindowFallbackTest — the redirect only fires for an
        // explicit out-of-window `t`, never for a missing one). Asserting a
        // redirect here would be asserting unreachable code, so this checks the
        // same intent (bib not matched) via the search-chip absence instead.
        $crawler = $client->request(Request::METHOD_GET, '/e/nobib-2026/photos?q=1423');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[data-testid="time-filter"]'));
        $this->assertCount(0, $crawler->filter('[data-testid="search-chip"]'));
    }

    public function testBibQueryMatchesWhenToggleOn(): void
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

        $crawler = $client->request(Request::METHOD_GET, '/e/bib-2026/photos?q=1423');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[data-lightbox-target="trigger"]'));
        $this->assertSelectorTextContains('[data-testid="search-chip"]', 'bib 1423');
    }
}
