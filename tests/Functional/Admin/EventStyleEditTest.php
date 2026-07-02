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

final class EventStyleEditTest extends WebTestCase
{
    public function testSubmittingCustomButtonColorPersistsIt(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em    = $container->get(EntityManagerInterface::class);
        $alice = $this->seedOrganizer();

        $event = new Event(
            'style-test-slug',
            'Style Test Event',
            new DateTimeImmutable('2026-08-01 10:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-08-01 14:00:00', new DateTimeZone('UTC')),
            $alice,
        );
        $em->persist($event);
        $em->flush();

        $eventId = (int) $event->getId();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', $eventId));
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form([
            'event[style][customButtonColor]' => '1',
            'event[style][buttonColor]'       => '#FF6B35',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/admin/events');

        /** @var EventRepository $events */
        $events   = $container->get(EventRepository::class);
        $reloaded = $events->find($eventId);
        $this->assertInstanceOf(Event::class, $reloaded);

        $this->assertSame('#FF6B35', $reloaded->getStyle()->getButtonColor());
    }

    public function testLeavingCustomFontColorUncheckedKeepsFontColorNull(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em    = $container->get(EntityManagerInterface::class);
        $alice = $this->seedOrganizer();

        $event = new Event(
            'style-font-slug',
            'Style Font Test Event',
            new DateTimeImmutable('2026-08-02 10:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-08-02 14:00:00', new DateTimeZone('UTC')),
            $alice,
        );
        $em->persist($event);
        $em->flush();

        $eventId = (int) $event->getId();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', $eventId));
        self::assertResponseIsSuccessful();

        // Submit form without setting customFontColor (leave unchecked)
        $form = $crawler->selectButton('Save')->form();
        // Uncheck customFontColor explicitly
        $form->remove('event[style][customFontColor]');

        $client->submit($form);

        self::assertResponseRedirects('/admin/events');

        /** @var EventRepository $events */
        $events   = $container->get(EventRepository::class);
        $reloaded = $events->find($eventId);
        $this->assertInstanceOf(Event::class, $reloaded);

        $this->assertNull($reloaded->getStyle()->getFontColor());
    }

    private function seedOrganizer(): User
    {
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $alice = new User('alice-style@example.com', 'Alice Style');
        $alice->addRole('ROLE_ORGANIZER');
        $alice->setPassword($hasher->hashPassword($alice, 'pw'));

        $em->persist($alice);
        $em->flush();

        return $alice;
    }
}
