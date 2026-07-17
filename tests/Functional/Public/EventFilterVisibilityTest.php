<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Tests\Support\PhotoFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The attribute-filter UI must only appear once there is something to filter on.
 * An event whose photos have no extracted tags/bibs should show no filter form.
 */
final class EventFilterVisibilityTest extends WebTestCase
{
    public function testFilterHiddenWhenNoAttributeData(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $event = PhotoFixtures::event($em, slug: 'notags-2026');
        PhotoFixtures::readyPhoto($em, $event, '2026-07-15 10:00:00');
        $em->flush();

        // t=12:00 keeps the windowed path in-window (event window 09:00–18:00),
        // otherwise a bare request 302-redirects to add ?t=.
        $crawler = $client->request(Request::METHOD_GET, '/e/notags-2026/photos?t=12:00');

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('[data-testid="attribute-filter"]'));
    }

    public function testFilterShownWhenAttributeDataExists(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $event = PhotoFixtures::event($em, slug: 'tags-2026');
        $photo = PhotoFixtures::readyPhoto($em, $event, '2026-07-15 10:00:00');
        PhotoFixtures::tagColour($em, $photo, 'orange');
        $em->flush();

        $crawler = $client->request(Request::METHOD_GET, '/e/tags-2026/photos?t=12:00');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[data-testid="attribute-filter"]'));
    }
}
