<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\User;
use App\Repository\EventRepository;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EventWindowFormTest extends WebTestCase
{
    public function testCreateEventComposesUtcStartAndEnd(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        $alice     = $this->seedOrganizer();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, '/admin/events/new');
        $form    = $crawler->selectButton('Create')->form([
            'event[name]'      => 'Window Event',
            'event[eventDate]' => '2026-07-15',
            'event[startTime]' => '10:00',
            'event[endTime]'   => '14:00',
            'event[timezone]'  => 'Europe/Amsterdam',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/admin/events');

        /** @var EventRepository $events */
        $events  = $container->get(EventRepository::class);
        $created = $events->findOneBy(['name' => 'Window Event']);
        $this->assertInstanceOf(Event::class, $created);

        $this->assertSame(
            '2026-07-15 08:00:00',
            $created->getStartsAt()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-07-15 12:00:00',
            $created->getEndsAt()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        );
    }

    public function testCreateEventRejectsInvalidStartTimeFormat(): void
    {
        $client = self::createClient();
        $alice  = $this->seedOrganizer();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, '/admin/events/new');
        $form    = $crawler->selectButton('Create')->form([
            'event[name]'      => 'Bad Time',
            'event[eventDate]' => '2026-07-15',
            'event[startTime]' => '25:99',
            'event[endTime]'   => '14:00',
            'event[timezone]'  => 'Europe/Amsterdam',
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
    }

    private function seedOrganizer(): User
    {
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $alice = new User('alice@example.com', 'Alice');
        $alice->addRole('ROLE_ORGANIZER');
        $alice->setPassword($hasher->hashPassword($alice, 'pw'));

        $em->persist($alice);
        $em->flush();

        return $alice;
    }
}
