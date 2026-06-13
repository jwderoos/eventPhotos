<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class MidnightCrossingTest extends WebTestCase
{
    private function seedMidnightEvent(): void
    {
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $owner = new User('mc-owner@example.test', 'O');
        $owner->setPassword('x');

        $em->persist($owner);

        // 2026-06-12 22:00 Europe/Amsterdam (UTC+2 in summer) → 2026-06-13 02:00 Europe/Amsterdam
        $tz       = new DateTimeZone('Europe/Amsterdam');
        $startsAt = new DateTimeImmutable('2026-06-12 22:00', $tz);
        $endsAt   = new DateTimeImmutable('2026-06-13 02:00', $tz);

        $event = new Event('midnight', 'Midnight', $startsAt, $endsAt, $owner);
        $event->setTimezone('Europe/Amsterdam');

        $em->persist($event);
        $em->flush();
    }

    public function testResolvesTimeOnStartsAtDate(): void
    {
        $client = self::createClient();
        $this->seedMidnightEvent();

        $client->request(Request::METHOD_GET, '/e/midnight/photos?t=23:30');
        $this->assertResponseIsSuccessful();
    }

    public function testResolvesTimeOnEndsAtDateAfterMidnight(): void
    {
        $client = self::createClient();
        $this->seedMidnightEvent();

        $client->request(Request::METHOD_GET, '/e/midnight/photos?t=01:30');
        $this->assertResponseIsSuccessful();
    }

    public function testRedirectsTimeOutsideBothDatesToEventStart(): void
    {
        $client = self::createClient();
        $this->seedMidnightEvent();

        // 10:00 maps to neither 22:00..23:59 on day 1 nor 00:00..02:00 on day 2;
        // per issue #59 the route 302s to ?t={startsAt H:i} rather than rejecting.
        $client->request(Request::METHOD_GET, '/e/midnight/photos?t=10:00');
        $this->assertResponseStatusCodeSame(Response::HTTP_FOUND);
        $this->assertResponseRedirects('/e/midnight/photos?t=22:00');
    }
}
