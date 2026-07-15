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

final class PhotoReingestUiTest extends WebTestCase
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

        $owner = new User('o@example.test', 'O');
        $owner->setPassword($hasher->hashPassword($owner, 'x'));
        $owner->addRole('ROLE_ORGANIZER');

        $this->em->persist($owner);
        $this->em->flush();

        $this->owner = $owner;
        $this->client->loginUser($owner);
    }

    private function makeEventWithReady(bool $retainOriginals): Event
    {
        $event = new Event(
            'e' . bin2hex(random_bytes(4)),
            'E',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $this->owner,
        );
        $event->setTimezone('UTC');
        $event->setRetainOriginals($retainOriginals);

        $this->em->persist($event);

        $photo = new Photo($event, str_repeat('a', 64), 'a.jpg', 100);
        $photo->markReady(new DateTimeImmutable('now', new DateTimeZone('UTC')), 100, 100, 2048);

        $this->em->persist($photo);
        $this->em->flush();

        return $event;
    }

    public function testReingestControlsShownWhenRetaining(): void
    {
        $event = $this->makeEventWithReady(retainOriginals: true);

        $this->client->request(Request::METHOD_GET, sprintf('/admin/events/%d/photos-grid', (int) $event->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('form[action$="/events/%d/photos/reingest"]', (int) $event->getId()));
        $this->assertStringContainsString('/reingest', (string) $this->client->getResponse()->getContent());
        self::assertSelectorExists('tbody form[action*="/reingest"]');
    }

    public function testReingestControlsHiddenWhenNotRetaining(): void
    {
        $event = $this->makeEventWithReady(retainOriginals: false);

        $this->client->request(Request::METHOD_GET, sprintf('/admin/events/%d/photos-grid', (int) $event->getId()));

        self::assertResponseIsSuccessful();
        $this->assertStringNotContainsString('/reingest', (string) $this->client->getResponse()->getContent());
    }
}
