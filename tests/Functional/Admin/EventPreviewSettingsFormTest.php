<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\PreviewSettings;
use App\Entity\User;
use App\Repository\EventRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EventPreviewSettingsFormTest extends WebTestCase
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

        $this->owner = new User('preview-owner@example.test', 'Owner');
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
            'Preview',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $this->owner,
        );
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    public function testNewEventDefaultsToLegacyValues(): void
    {
        $event = $this->makeEvent('preview-defaults');

        $this->assertSame(PreviewSettings::DEFAULT_LONG_EDGE, $event->getPreviewSettings()->getLongEdge());
        $this->assertSame(PreviewSettings::DEFAULT_QUALITY, $event->getPreviewSettings()->getQuality());
    }

    public function testAllowlistedValuePersists(): void
    {
        $event = $this->makeEvent('preview-valid');

        $crawler = $this->client->request(
            Request::METHOD_GET,
            sprintf('/admin/events/%d/edit', (int) $event->getId()),
        );
        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('#event_preview_longEdge'), 'preview size select must render.');

        $form = $crawler->selectButton('Save')->form();
        /** @phpstan-ignore-next-line method.nonObject */
        $form['event[preview][longEdge]']->select('2048');
        /** @phpstan-ignore-next-line method.nonObject */
        $form['event[preview][quality]']->select('90');
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/events');

        /** @var EventRepository $events */
        $events   = self::getContainer()->get(EventRepository::class);
        $reloaded = $events->find((int) $event->getId());
        $this->assertInstanceOf(Event::class, $reloaded);
        $this->assertSame(2048, $reloaded->getPreviewSettings()->getLongEdge());
        $this->assertSame(90, $reloaded->getPreviewSettings()->getQuality());
    }

    public function testOutOfAllowlistValueIsRejected(): void
    {
        $event = $this->makeEvent('preview-tamper');

        $crawler = $this->client->request(
            Request::METHOD_GET,
            sprintf('/admin/events/%d/edit', (int) $event->getId()),
        );
        $form   = $crawler->selectButton('Save')->form();
        $values = $form->getPhpValues();
        $this->assertIsArray($values['event']);
        $this->assertIsArray($values['event']['preview']);
        $values['event']['preview']['longEdge'] = '9999';
        $this->client->request(Request::METHOD_POST, $form->getUri(), $values);

        // Invalid submit re-renders the form (422 Unprocessable Content) instead of redirecting, and nothing persists.
        self::assertResponseStatusCodeSame(422);

        /** @var EventRepository $events */
        $events   = self::getContainer()->get(EventRepository::class);
        $reloaded = $events->find((int) $event->getId());
        $this->assertInstanceOf(Event::class, $reloaded);
        $this->assertSame(
            PreviewSettings::DEFAULT_LONG_EDGE,
            $reloaded->getPreviewSettings()->getLongEdge(),
            'An out-of-allowlist value must be rejected — the stored value stays at the default.',
        );
    }
}
