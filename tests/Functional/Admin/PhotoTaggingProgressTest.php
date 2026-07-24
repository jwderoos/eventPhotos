<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PhotoTaggingProgressTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $owner;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $c            = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $this->em = $em;

        $owner = new User('o2@example.test', 'O2');
        $owner->setPassword($hasher->hashPassword($owner, 'x'));
        $owner->addRole('ROLE_ORGANIZER');

        $this->em->persist($owner);
        $this->em->flush();

        $this->owner = $owner;
        $this->client->loginUser($owner);
    }

    public function testGridShowsTaggingProgressAndPerRowState(): void
    {
        $event = new Event(
            'e' . bin2hex(random_bytes(4)),
            'E',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $this->owner,
        );
        $event->setTimezone('UTC');

        $this->em->persist($event);

        $tagged = new Photo($event, str_repeat('a', 64), 'a.jpg', 100);
        $tagged->markReady(new DateTimeImmutable('now', new DateTimeZone('UTC')), 100, 100, 2048);
        $tagged->markAttributesExtracted();

        $untagged = new Photo($event, str_repeat('b', 64), 'b.jpg', 100);
        $untagged->markReady(new DateTimeImmutable('now', new DateTimeZone('UTC')), 100, 100, 2048);

        $this->em->persist($tagged);
        $this->em->persist($untagged);
        $this->em->flush();

        $this->client->request(Request::METHOD_GET, sprintf('/admin/events/%d/photos-grid', (int) $event->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="tagging-progress"]', '1 / 2');
        self::assertSelectorExists(sprintf('tr[data-photo-id="%d"][data-tagging="done"]', (int) $tagged->getId()));
        self::assertSelectorExists(sprintf('tr[data-photo-id="%d"][data-tagging="pending"]', (int) $untagged->getId()));
        self::assertSelectorExists('turbo-frame#photos-grid [data-processing-incomplete]');
    }

    public function testGridHidesProcessingIncompleteMarkerWhenAllTagged(): void
    {
        $event = new Event(
            'e' . bin2hex(random_bytes(4)),
            'E',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $this->owner,
        );
        $event->setTimezone('UTC');

        $this->em->persist($event);

        $tagged = new Photo($event, str_repeat('a', 64), 'a.jpg', 100);
        $tagged->markReady(new DateTimeImmutable('now', new DateTimeZone('UTC')), 100, 100, 2048);
        $tagged->markAttributesExtracted();

        $this->em->persist($tagged);
        $this->em->flush();

        $this->client->request(Request::METHOD_GET, sprintf('/admin/events/%d/photos-grid', (int) $event->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="tagging-progress"]', '1 / 1');
        self::assertSelectorNotExists('turbo-frame#photos-grid [data-processing-incomplete]');
    }
}
