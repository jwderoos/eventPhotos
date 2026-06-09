<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EventLandingTest extends WebTestCase
{
    public function testLandingShowsEventNameAndShareControls(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('owner@example.com', 'Owner');
        $owner->setPassword('x');

        $em->persist($owner);
        $em->persist(new Event('summer-fest', 'Summer Fest', new DateTimeImmutable('2026-07-15'), $owner));
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/summer-fest');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Summer Fest');
        $this->assertSelectorExists('button[data-action*="share#share"]');
        $this->assertSelectorExists('a[href*="/e/summer-fest/photos?t="]');
    }

    public function testLandingReturns404ForUnknownSlug(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/e/does-not-exist');

        $this->assertResponseStatusCodeSame(404);
    }
}
