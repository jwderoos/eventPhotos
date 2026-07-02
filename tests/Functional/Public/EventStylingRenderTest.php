<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EventStylingRenderTest extends WebTestCase
{
    public function testStyledEventEmitsCssVariables(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('styled-event-test@example.com', 'StyledOwner');
        $owner->setPassword('x');

        $event = new Event(
            'styled-event-slug',
            'Styled Event',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );
        $event->getStyle()->setFontColor('#123456');
        $event->getStyle()->setButtonColor('#FF6B35');
        $event->getStyle()->setGlowEnabled(true);

        $em->persist($owner);
        $em->persist($event);
        $em->flush();

        $crawler = $client->request(Request::METHOD_GET, '/e/' . $event->getSlug());

        $this->assertResponseIsSuccessful();

        $root = $crawler->filter('[data-style-root]');
        $this->assertGreaterThan(0, $root->count(), '[data-style-root] element not found');

        $style = $root->attr('style');
        $this->assertIsString($style, '[data-style-root] has no style attribute');
        $this->assertStringContainsString('--color-base-content: #123456', $style);
        $this->assertStringContainsString('--color-primary: #FF6B35', $style);
        $this->assertStringContainsString('radial-gradient(circle, rgba(255, 107, 53, 0.4)', $style);
    }

    public function testUnstyledEventEmitsNoStyleOverrides(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('unstyled-event-test@example.com', 'UnstyledOwner');
        $owner->setPassword('x');

        $event = new Event(
            'unstyled-event-slug',
            'Unstyled Event',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );
        // All style fields remain null — no setFontColor, setButtonColor, setGlowEnabled calls.

        $em->persist($owner);
        $em->persist($event);
        $em->flush();

        $crawler = $client->request(Request::METHOD_GET, '/e/' . $event->getSlug());

        $this->assertResponseIsSuccessful();

        $root = $crawler->filter('[data-style-root]');
        $this->assertGreaterThan(0, $root->count(), '[data-style-root] element must always be present');

        // Wrapper present but must carry no style attribute at all
        $this->assertNull($root->attr('style'), 'Unstyled event must carry no style attribute');
    }
}
