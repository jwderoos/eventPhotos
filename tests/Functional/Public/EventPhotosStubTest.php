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
    public function testPhotosPageRendersWithShortFormTimestamp(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('owner@example.com', 'Owner');
        $owner->setPassword('x');

        $em->persist($owner);
        $em->persist(new Event('summer-fest', 'Summer Fest', new DateTimeImmutable('2026-07-15'), $owner));
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/summer-fest/photos?t=18:30');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Summer Fest');
        $this->assertSelectorTextContains('[data-testid="timestamp"]', '18:30');
        $this->assertSelectorTextContains(
            '[data-testid="window"]',
            (string) Event::DEFAULT_WINDOW_MINUTES,
        );
    }

    public function testMissingTimestampFallsBackToNow(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('owner@example.com', 'Owner');
        $owner->setPassword('x');

        $em->persist($owner);
        $em->persist(new Event('summer-fest', 'Summer Fest', new DateTimeImmutable('2026-07-15'), $owner));
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/summer-fest/photos');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains(
            '[data-testid="window"]',
            (string) Event::DEFAULT_WINDOW_MINUTES,
        );
    }

    public function testEditableTimeInputRendersWithCurrentValue(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('owner@example.com', 'Owner');
        $owner->setPassword('x');

        $em->persist($owner);
        $em->persist(new Event('summer-fest', 'Summer Fest', new DateTimeImmutable('2026-07-15'), $owner));
        $em->flush();

        $crawler = $client->request(Request::METHOD_GET, '/e/summer-fest/photos?t=09:15');

        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form[data-testid="time-filter"]');
        $this->assertGreaterThan(0, $form->count(), 'Editable time filter form should be present');
        $this->assertSame('get', strtolower((string) $form->attr('method')));
        $this->assertStringEndsWith('/e/summer-fest/photos', (string) $form->attr('action'));

        $input = $form->filter('input[name="t"]');
        $this->assertGreaterThan(0, $input->count(), 'Time input must be present');
        $this->assertSame('time', $input->attr('type'));
        $this->assertSame('09:15', $input->attr('value'));
    }

    public function testInvalidShortFormTimestampReturns400(): void
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

    public function testLegacyAtomTimestampReturns400(): void
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

        $this->assertResponseStatusCodeSame(400);
    }

    public function testWindowQueryParamReturns400(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('owner@example.com', 'Owner');
        $owner->setPassword('x');

        $em->persist($owner);
        $em->persist(new Event('summer-fest', 'Summer Fest', new DateTimeImmutable('2026-07-15'), $owner));
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/summer-fest/photos?t=12:00&w=20');

        $this->assertResponseStatusCodeSame(400);
    }
}
