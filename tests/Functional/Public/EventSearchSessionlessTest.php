<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Tests\Support\PhotoFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EventSearchSessionlessTest extends WebTestCase
{
    public function testSearchResponseSetsNoSessionCookie(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $event = PhotoFixtures::event($em, slug: 'sessionless-2026');
        $photo = PhotoFixtures::readyPhoto($em, $event, '2026-07-15 10:00:00');
        PhotoFixtures::tagColour($em, $photo, 'orange');
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/sessionless-2026/photos?colour%5B%5D=orange');

        $this->assertResponseIsSuccessful();

        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            $this->assertNotSame(
                session_name(),
                $cookie->getName(),
                'Public search route must not start a session.',
            );
        }
    }
}
