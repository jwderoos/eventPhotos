<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\EventNotificationSubscription;
use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Service\Mail\DsnVault;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EventNotificationCountsTest extends WebTestCase
{
    public function testEditPageShowsConfirmedOfTotal(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('counts-owner@example.com', 'Owner');
        $owner->addRole('ROLE_ORGANIZER');

        $em->persist($owner);

        /** @var DsnVault $vault */
        $vault = self::getContainer()->get(DsnVault::class);
        $config = new UserMailConfig(
            $owner,
            $vault->encrypt('smtp://x@smtp.example-organizer.test:25'),
            'counts-owner@example.com',
            null,
        );
        $config->markVerified();

        $em->persist($config);

        $event = new Event(
            slug: 'counts-dash-event',
            name: 'Counts Dash',
            startsAt: new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            endsAt: new DateTimeImmutable('2026-01-01 18:00:00', new DateTimeZone('UTC')),
            owner: $owner,
        );
        $event->enableNotifications();

        $em->persist($event);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $confirmed = new EventNotificationSubscription($event, 'c@example.com', $now);
        $confirmed->confirm($now);

        $em->persist($confirmed);
        $em->persist(new EventNotificationSubscription($event, 'p@example.com', $now));
        $em->flush();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/admin/events/' . $event->getId() . '/edit');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '1 confirmed of 2 total');
    }
}
