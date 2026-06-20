<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicRateLimitTest extends WebTestCase
{
    /** Mirrors framework.rate_limiter.public_event.limit in config/packages/rate_limiter.yaml. */
    private const int LIMIT = 120;

    public function testEnumerationIsRateLimitedAfterTheConfiguredCeiling(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->clearRateLimiter();

        // Seed a user so FirstRunBootstrapSubscriber doesn't redirect to /setup.
        /** @var EntityManagerInterface $em */
        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $owner = new User('rate-enum@example.com', 'Enum Owner');
        $owner->setPassword('x');

        $em->persist($owner);
        $em->flush();

        $ip = '203.0.113.10';

        for ($i = 1; $i <= self::LIMIT; ++$i) {
            $client->request(Request::METHOD_GET, '/e/no-such-event', [], [], ['REMOTE_ADDR' => $ip]);
            $this->assertSame(
                Response::HTTP_NOT_FOUND,
                $client->getResponse()->getStatusCode(),
                sprintf('Request %d should hit the 404 path, not be rate-limited yet', $i),
            );
        }

        $client->request(Request::METHOD_GET, '/e/no-such-event', [], [], ['REMOTE_ADDR' => $ip]);

        $this->assertSame(
            Response::HTTP_TOO_MANY_REQUESTS,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
        $this->assertTrue(
            $client->getResponse()->headers->has('Retry-After'),
            '429 response must carry a Retry-After header',
        );
    }

    public function testValidLandingRequestsAlsoCountTowardTheLimit(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->clearRateLimiter();

        /** @var EntityManagerInterface $em */
        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $owner = new User('rate-owner@example.com', 'Owner');
        $owner->setPassword('x');

        $em->persist($owner);
        $em->persist(new Event(
            'rate-fest',
            'Rate Fest',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        ));
        $em->flush();

        $ip = '203.0.113.20';

        for ($i = 1; $i <= self::LIMIT; ++$i) {
            $client->request(Request::METHOD_GET, '/e/rate-fest', [], [], ['REMOTE_ADDR' => $ip]);
            $this->assertTrue(
                $client->getResponse()->isSuccessful(),
                sprintf('Request %d to a valid event should succeed before the ceiling', $i),
            );
        }

        $client->request(Request::METHOD_GET, '/e/rate-fest', [], [], ['REMOTE_ADDR' => $ip]);

        $this->assertSame(
            Response::HTTP_TOO_MANY_REQUESTS,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testPhotoServeIsNotRateLimited(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->clearRateLimiter();

        // Seed a user so FirstRunBootstrapSubscriber doesn't redirect to /setup.
        /** @var EntityManagerInterface $em */
        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $owner = new User('photo-serve-owner@example.com', 'Owner');
        $owner->setPassword('x');

        $em->persist($owner);
        $em->flush();

        $ip = '203.0.113.30';

        // One past the ceiling. The photo doesn't exist (404), but if the route
        // were limited the listener would 429 before the controller 404'd.
        for ($i = 1; $i <= self::LIMIT + 1; ++$i) {
            $client->request(
                Request::METHOD_GET,
                '/e/no-such-event/p/999/thumb.jpg',
                [],
                [],
                ['REMOTE_ADDR' => $ip],
            );
            $this->assertNotSame(
                Response::HTTP_TOO_MANY_REQUESTS,
                $client->getResponse()->getStatusCode(),
                sprintf('photo-serve request %d must never be rate-limited', $i),
            );
        }
    }

    public function testNeighborEndpointIsNotRateLimited(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->clearRateLimiter();

        /** @var EntityManagerInterface $em */
        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $owner = new User('neighbor-owner@example.com', 'Owner');
        $owner->setPassword('x');

        $em->persist($owner);
        $em->persist(new Event(
            'neighbor-fest',
            'Neighbor Fest',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        ));
        $em->flush();

        $ip = '203.0.113.40';

        for ($i = 1; $i <= self::LIMIT + 1; ++$i) {
            $client->request(
                Request::METHOD_GET,
                '/e/neighbor-fest/photos/999/neighbor?direction=next',
                [],
                [],
                ['REMOTE_ADDR' => $ip],
            );
            $this->assertNotSame(
                Response::HTTP_TOO_MANY_REQUESTS,
                $client->getResponse()->getStatusCode(),
                sprintf('neighbor request %d must never be rate-limited', $i),
            );
        }
    }

    private function clearRateLimiter(): void
    {
        $pool = self::getContainer()->get('rate_limiter.cache_pool');
        $this->assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }
}
