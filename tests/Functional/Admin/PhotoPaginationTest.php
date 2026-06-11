<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PhotoPaginationTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Event $event;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $c            = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);

        $this->em = $em;

        $owner = new User('owner@example.test', 'Owner');
        $owner->setPassword($hasher->hashPassword($owner, 'secret'));
        $owner->addRole('ROLE_ORGANIZER');

        $this->em->persist($owner);

        $this->event = new Event('demo', 'Demo', new DateTimeImmutable('2026-06-10'), $owner);
        $this->event->setTimezone('Europe/Amsterdam');

        $this->em->persist($this->event);
        $this->em->flush();

        $this->client->loginUser($owner);
    }

    public function testFirstPageShowsHundredRows(): void
    {
        $this->seed(150);

        $url = sprintf('/admin/events/%d/photos-grid', (int) $this->event->getId());
        $crawler = $this->client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $this->assertCount(100, $crawler->filter('table tbody tr'));
    }

    public function testSecondPageShowsRemainingFifty(): void
    {
        $this->seed(150);

        $url = sprintf('/admin/events/%d/photos-grid?page=2', (int) $this->event->getId());
        $crawler = $this->client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $this->assertCount(50, $crawler->filter('table tbody tr'));
    }

    public function testPastLastPageIsEmpty(): void
    {
        $this->seed(150);

        $url = sprintf('/admin/events/%d/photos-grid?page=3', (int) $this->event->getId());
        $crawler = $this->client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('table tbody tr'));
    }

    private function seed(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $photo = new Photo(
                event: $this->event,
                contentHash: bin2hex(random_bytes(32)),
                originalFilename: 'f-' . $i . '.jpg',
                byteSize: 100,
            );
            $this->em->persist($photo);
        }

        $this->em->flush();
    }
}
