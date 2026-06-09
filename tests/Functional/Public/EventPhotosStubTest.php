<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EventPhotosStubTest extends WebTestCase
{
    public function testPhotosPageRendersWithTimestampAndWindow(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('owner@example.com', 'Owner');
        $owner->setPassword('x');

        $em->persist($owner);
        $em->persist(new Event('summer-fest', 'Summer Fest', new DateTimeImmutable('2026-07-15'), $owner));
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/summer-fest/photos?t=2026-07-15T18:30:00%2B00:00&w=20');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Summer Fest');
        $this->assertSelectorTextContains('[data-testid="window"]', '20');
        $this->assertSelectorTextContains('[data-testid="timestamp"]', '18:30');
    }

    public function testPhotosPageFallsBackToEventDefaultWindowWhenMissing(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('owner@example.com', 'Owner');
        $owner->setPassword('x');

        $em->persist($owner);
        $em->persist(new Event('summer-fest', 'Summer Fest', new DateTimeImmutable('2026-07-15'), $owner));
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/summer-fest/photos?t=2026-07-15T18:30:00%2B00:00');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="window"]', (string) Event::DEFAULT_WINDOW_MINUTES);
    }

    public function testInvalidTimestampReturns400(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('owner@example.com', 'Owner');
        $owner->setPassword('x');

        $em->persist($owner);
        $em->persist(new Event('summer-fest', 'Summer Fest', new DateTimeImmutable('2026-07-15'), $owner));
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/summer-fest/photos?t=not-a-date');

        $this->assertResponseStatusCodeSame(400);
    }
}
