<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\User;
use App\Repository\EventRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EventRetainOriginalsFormTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $owner;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $c = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $this->em = $em;

        $this->owner = new User('retain-owner@example.test', 'Owner');
        $this->owner->setPassword($hasher->hashPassword($this->owner, 'secret'));
        $this->owner->addRole('ROLE_ORGANIZER');

        $this->em->persist($this->owner);
        $this->em->flush();

        $this->client->loginUser($this->owner);
    }

    private function makeEvent(string $slug): Event
    {
        $event = new Event(
            $slug,
            'Retain',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $this->owner,
        );
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    public function testToggleEditableWhenNoPhotosAndPersists(): void
    {
        $event = $this->makeEvent('retain-editable');

        $crawler = $this->client->request(
            Request::METHOD_GET,
            sprintf('/admin/events/%d/edit', (int) $event->getId()),
        );
        self::assertResponseIsSuccessful();

        $checkbox = $crawler->filter('#event_retainOriginals');
        $this->assertCount(1, $checkbox, 'retainOriginals checkbox must render.');
        $this->assertNull(
            $checkbox->attr('disabled'),
            'Checkbox must be enabled when the event has no photos.',
        );

        $form = $crawler->selectButton('Save')->form();
        /** @phpstan-ignore-next-line method.nonObject */
        $form['event[retainOriginals]']->tick();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/events');

        /** @var EventRepository $events */
        $events   = self::getContainer()->get(EventRepository::class);
        $reloaded = $events->find((int) $event->getId());
        $this->assertInstanceOf(Event::class, $reloaded);
        $this->assertTrue($reloaded->isRetainOriginals());
    }

    public function testToggleLockedWhenPhotosExist(): void
    {
        $event = $this->makeEvent('retain-locked');
        $photo = new Photo($event, str_pad('a', 64, '0'), 'a.jpg', 100);
        $this->em->persist($photo);
        $this->em->flush();

        $crawler = $this->client->request(
            Request::METHOD_GET,
            sprintf('/admin/events/%d/edit', (int) $event->getId()),
        );
        self::assertResponseIsSuccessful();

        $checkbox = $crawler->filter('#event_retainOriginals');
        $this->assertCount(1, $checkbox);
        $this->assertSame(
            'disabled',
            $checkbox->attr('disabled'),
            'Checkbox must be disabled once any photo exists.',
        );
    }

    public function testTamperedPostCannotFlipLockedToggle(): void
    {
        $event = $this->makeEvent('retain-tamper');
        $photo = new Photo($event, str_pad('b', 64, '0'), 'b.jpg', 100);
        $this->em->persist($photo);
        $this->em->flush();

        $eventId = (int) $event->getId();

        // Submit the edit form with a crafted retainOriginals value while locked.
        $crawler = $this->client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', $eventId));
        $form    = $crawler->selectButton('Save')->form();
        $values  = $form->getPhpValues();
        $this->assertIsArray($values['event']);
        $values['event']['retainOriginals'] = '1';
        $this->client->request(Request::METHOD_POST, $form->getUri(), $values);

        /** @var EventRepository $events */
        $events   = self::getContainer()->get(EventRepository::class);
        $reloaded = $events->find($eventId);
        $this->assertInstanceOf(Event::class, $reloaded);
        $this->assertFalse(
            $reloaded->isRetainOriginals(),
            'A disabled field must ignore submitted data — the flag stays false.',
        );
    }
}
