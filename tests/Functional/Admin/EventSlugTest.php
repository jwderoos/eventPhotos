<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\User;
use App\Repository\EventRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EventSlugTest extends WebTestCase
{
    public function testNewFormHasNoSlugInput(): void
    {
        $client = self::createClient();
        $alice = $this->seedOrganizer();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, '/admin/events/new');

        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('input[name="event[slug]"]'));
        $this->assertCount(0, $crawler->filter('textarea[name="event[slug]"]'));
    }

    public function testCreatePopulatesSlugAutomatically(): void
    {
        $client = self::createClient();
        $container = self::getContainer();
        $alice = $this->seedOrganizer();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, '/admin/events/new');
        $form = $crawler->selectButton('Create')->form([
            'event[name]' => 'My Brand New Event',
            'event[date]' => '2026-08-01',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/admin/events');

        /** @var EventRepository $events */
        $events = $container->get(EventRepository::class);
        $created = $events->findOneBy(['name' => 'My Brand New Event']);
        $this->assertInstanceOf(Event::class, $created);
        $this->assertMatchesRegularExpression('/^my-brand-new-event-[a-z0-9]{6}$/', $created->getSlug());
    }

    public function testEditFormHasNoSlugInputAndEditDoesNotChangeSlug(): void
    {
        $client = self::createClient();
        $container = self::getContainer();
        $alice = $this->seedOrganizer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $event = new Event('legacy-slug-xyz999', 'Original Name', new DateTimeImmutable('2026-07-15'), $alice);
        $em->persist($event);
        $em->flush();

        $eventId = (int) $event->getId();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', $eventId));

        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('input[name="event[slug]"]'));

        $form = $crawler->selectButton('Save')->form([
            'event[name]' => 'Renamed Event',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/admin/events');

        $em->clear();
        /** @var EventRepository $events */
        $events = $container->get(EventRepository::class);
        $reloaded = $events->find($eventId);
        $this->assertInstanceOf(Event::class, $reloaded);
        $this->assertSame('legacy-slug-xyz999', $reloaded->getSlug(), 'slug must not change on edit');
        $this->assertSame('Renamed Event', $reloaded->getName());
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
