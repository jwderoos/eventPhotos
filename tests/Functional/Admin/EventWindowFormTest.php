<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\User;
use App\Repository\EventRepository;
use DateTimeImmutable;
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

    public function testEditFormPrefillsDateAndTimeFromExistingEvent(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em    = $container->get(EntityManagerInterface::class);
        $alice = $this->seedOrganizer();

        $event = new Event(
            'prefill-fest',
            'Prefill Fest',
            new DateTimeImmutable('2026-07-15 08:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-07-15 12:00:00', new DateTimeZone('UTC')),
            $alice,
        );
        $em->persist($event);
        $em->flush();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', (int) $event->getId()));
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form();

        /** @phpstan-ignore-next-line method.nonObject */
        $this->assertSame('2026-07-15', $form['event[eventDate]']->getValue());
        /** @phpstan-ignore-next-line method.nonObject */
        $this->assertSame('10:00', $form['event[startTime]']->getValue());
        /** @phpstan-ignore-next-line method.nonObject */
        $this->assertSame('14:00', $form['event[endTime]']->getValue());
    }

    public function testEditFormPreservesTimesAcrossCreatePersistReload(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        $alice     = $this->seedOrganizer();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, '/admin/events/new');
        $form    = $crawler->selectButton('Create')->form([
            'event[name]'      => 'Round Trip Fest',
            'event[eventDate]' => '2026-07-15',
            'event[startTime]' => '10:00',
            'event[endTime]'   => '14:00',
            'event[timezone]'  => 'Europe/Amsterdam',
        ]);
        $client->submit($form);
        self::assertResponseRedirects('/admin/events');

        /** @var EventRepository $events */
        $events  = $container->get(EventRepository::class);
        $created = $events->findOneBy(['name' => 'Round Trip Fest']);
        $this->assertInstanceOf(Event::class, $created);

        // Second HTTP request → fresh EntityManager → hydrates from DB. This is the
        // round-trip path that surfaced the wall-clock-vs-UTC mismatch (#TZ shift bug).
        $crawler = $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', (int) $created->getId()));
        self::assertResponseIsSuccessful();
        $editForm = $crawler->selectButton('Save')->form();

        /** @phpstan-ignore-next-line method.nonObject */
        $this->assertSame('10:00', $editForm['event[startTime]']->getValue());
        /** @phpstan-ignore-next-line method.nonObject */
        $this->assertSame('14:00', $editForm['event[endTime]']->getValue());

        // Re-save the form unchanged and re-read it: times must remain stable.
        $client->submit($editForm);
        self::assertResponseRedirects('/admin/events');

        $crawler  = $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', (int) $created->getId()));
        $editForm = $crawler->selectButton('Save')->form();
        /** @phpstan-ignore-next-line method.nonObject */
        $this->assertSame('10:00', $editForm['event[startTime]']->getValue());
        /** @phpstan-ignore-next-line method.nonObject */
        $this->assertSame('14:00', $editForm['event[endTime]']->getValue());
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
