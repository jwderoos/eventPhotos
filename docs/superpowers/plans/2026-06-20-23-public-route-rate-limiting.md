# Public Route Rate Limiting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a coarse per-client-IP rate limit (120 req/min, sliding window) to the anonymous public event HTML/JSON routes to blunt slug enumeration and request floods, returning HTTP 429 + `Retry-After` when exceeded.

**Architecture:** A `framework.rate_limiter` definition (`public_event`, sliding window) backed by a dedicated filesystem cache pool. A `kernel.request` listener (`PublicRateLimitListener`) runs after routing, matches `_route` against an explicit allowlist of four `public_event_*` routes, keys the limiter on the client IP, and throws `TooManyRequestsHttpException` when a request is rejected. Photo-serve and the lightbox neighbor route are deliberately excluded.

**Tech Stack:** PHP 8.5, Symfony 8 (`symfony/rate-limiter` already present), filesystem cache pool, PHPUnit 13 functional tests (`WebTestCase` + dama transactional bundle).

**Spec:** `docs/superpowers/specs/2026-06-20-23-public-route-rate-limiting-design.md`

## Global Constraints

- PHP 8.5 / Symfony 8. PHP attributes only — no annotations.
- PHPStan level 10 clean; `phpcs` PSR-12; `phpmnd` clean (no magic numbers in `src/` — numeric literals go in YAML config or typed class constants; numbers in `#[AsEventListener]` attribute args are tolerated, as proven by the existing `priority: -10` listener).
- `phpcpd` (50-line / 100-token duplication), `rector`, `doctrine:schema:validate` all gate commits. No schema change is expected in this work.
- Commit messages MUST contain the issue number — prefix every commit with `23 - `.
- Work happens on branch `feature/23-public-route-rate-limiting` (already created).
- Limiter values (`120`, `1 minute`) live in YAML, never in `src/`.
- Public anonymous routes must not touch the session (#68 invariant). The limiter is cache-backed, not session-backed, so this is preserved — do not introduce any session access.

---

### Task 1: Limiter config, cache pool, and the rate-limit listener

**Files:**
- Create: `config/packages/rate_limiter.yaml`
- Modify: `config/packages/cache.yaml` (add a dedicated pool)
- Create: `src/EventListener/PublicRateLimitListener.php`
- Test: `tests/Functional/Public/PublicRateLimitTest.php`

**Interfaces:**
- Consumes: the autowired limiter factory `Symfony\Component\RateLimiter\RateLimiterFactoryInterface $publicEventLimiter` (named after the `public_event` limiter key), and `Symfony\Component\Clock\ClockInterface`.
- Produces: `App\EventListener\PublicRateLimitListener` with `public const array LIMITED_ROUTES` (the allowlist) and `public function onRequest(RequestEvent $event): void`. A dedicated cache pool service id `rate_limiter.cache_pool` (used by tests to clear limiter state).

- [ ] **Step 1: Create the limiter config**

Create `config/packages/rate_limiter.yaml`:

```yaml
framework:
    rate_limiter:
        # Coarse per-client-IP cap on the public event HTML/JSON routes (#23).
        # Blunts slug enumeration + request floods. 120/min ≈ 2 req/s — generous
        # for a human browsing a gallery, brutal for a sprayer. Photo-serve and
        # the lightbox neighbor route are NOT limited (see the listener allowlist).
        public_event:
            policy: 'sliding_window'
            limit: 120
            interval: '1 minute'
            cache_pool: 'rate_limiter.cache_pool'
```

- [ ] **Step 2: Add the dedicated cache pool**

In `config/packages/cache.yaml`, replace the commented pools block:

```yaml
        # Namespaced pools use the above "app" backend by default
        #pools:
            #my.dedicated.cache: null
```

with a real pool so the limiter's state is isolated and clearable in tests:

```yaml
        # Namespaced pools use the above "app" backend by default
        pools:
            # Dedicated pool for the public-route rate limiter (#23) so its state
            # can be cleared in isolation by functional tests, and so limiter
            # churn never evicts unrelated application cache entries.
            rate_limiter.cache_pool:
                adapter: cache.adapter.filesystem
```

- [ ] **Step 3: Verify the container builds with the new limiter**

Run: `bin/console lint:container`
Expected: no errors; the `public_event` limiter and `rate_limiter.cache_pool` resolve.

- [ ] **Step 4: Write the failing functional test**

Create `tests/Functional/Public/PublicRateLimitTest.php`. This single test pins the configured ceiling AND proves the limiter runs *before* the controller (enumeration: 404s still count toward the limit).

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
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

        $ip = '203.0.113.10';

        for ($i = 1; $i <= self::LIMIT; $i++) {
            $client->request(Request::METHOD_GET, '/e/no-such-event', [], [], ['REMOTE_ADDR' => $ip]);
            self::assertSame(
                Response::HTTP_NOT_FOUND,
                $client->getResponse()->getStatusCode(),
                sprintf('Request %d should hit the 404 path, not be rate-limited yet', $i),
            );
        }

        $client->request(Request::METHOD_GET, '/e/no-such-event', [], [], ['REMOTE_ADDR' => $ip]);

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $client->getResponse()->getStatusCode());
        self::assertTrue(
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

        for ($i = 1; $i <= self::LIMIT; $i++) {
            $client->request(Request::METHOD_GET, '/e/rate-fest', [], [], ['REMOTE_ADDR' => $ip]);
            self::assertTrue(
                $client->getResponse()->isSuccessful(),
                sprintf('Request %d to a valid event should succeed before the ceiling', $i),
            );
        }

        $client->request(Request::METHOD_GET, '/e/rate-fest', [], [], ['REMOTE_ADDR' => $ip]);

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $client->getResponse()->getStatusCode());
    }

    private function clearRateLimiter(): void
    {
        $pool = self::getContainer()->get('rate_limiter.cache_pool');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }
}
```

- [ ] **Step 5: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Public/PublicRateLimitTest.php`
Expected: FAIL — without the listener, the 121st request returns 404 / 200, not 429 (and `rate_limiter.cache_pool` may already resolve from Step 2, so the failure is on the missing 429 behavior).

- [ ] **Step 6: Write the listener**

Create `src/EventListener/PublicRateLimitListener.php`:

```php
<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Coarse per-client-IP rate limit on the anonymous public event routes (#23).
 *
 * Priority 20 places this after the RouterListener (32) — so `_route` is set —
 * and before the firewall (8), so floods are rejected before any auth work.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onRequest', priority: 20)]
final readonly class PublicRateLimitListener
{
    /**
     * Route names sharing one per-client-IP bucket. Photo-serve (high-volume
     * legit traffic, ~200 requests/page) and the lightbox neighbor endpoint
     * (cheap, not an enumeration vector) are deliberately absent — see
     * docs/superpowers/specs/2026-06-20-23-public-route-rate-limiting-design.md.
     *
     * @var list<string>
     */
    public const array LIMITED_ROUTES = [
        'public_event_landing',
        'public_event_photos',
        'public_event_display',
        'public_event_display_qr',
    ];

    public function __construct(
        private RateLimiterFactoryInterface $publicEventLimiter,
        private ClockInterface $clock,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route   = $request->attributes->get('_route');
        if (!is_string($route) || !in_array($route, self::LIMITED_ROUTES, true)) {
            return;
        }

        $clientIp = $request->getClientIp();
        if ($clientIp === null) {
            return;
        }

        $limit = $this->publicEventLimiter->create($clientIp)->consume();
        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp();
        throw new TooManyRequestsHttpException(max(1, $retryAfter));
    }
}
```

Notes for the implementer:
- `$publicEventLimiter` is auto-injected by name from the `public_event` limiter key — no manual service wiring needed.
- `consume()` defaults to consuming 1 token. `getRetryAfter()` returns a `DateTimeImmutable`; `max(1, …)` clamps to a non-zero `Retry-After` (the literals `1` are in phpmnd's default ignore set).

- [ ] **Step 7: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Public/PublicRateLimitTest.php`
Expected: PASS (both test methods green).

- [ ] **Step 8: Run the quality gates**

Run: `vendor/bin/phpstan analyse src/EventListener/PublicRateLimitListener.php tests/Functional/Public/PublicRateLimitTest.php`
Expected: no errors at level 10.

Run: `vendor/bin/phpcs src/EventListener/PublicRateLimitListener.php tests/Functional/Public/PublicRateLimitTest.php`
Expected: PSR-12 clean.

- [ ] **Step 9: Commit**

```bash
git add config/packages/rate_limiter.yaml config/packages/cache.yaml \
        src/EventListener/PublicRateLimitListener.php \
        tests/Functional/Public/PublicRateLimitTest.php
git commit -m "23 - rate limit public event routes (120/min/IP, sliding window)"
```

---

### Task 2: Verify the exclusions (photo-serve and neighbor are NOT limited)

These tests pin the allowlist boundary so a future edit that accidentally limits the image routes (which would break galleries) fails loudly. They use the 404 path: if those routes *were* limited, the limiter would reject the (LIMIT + 1)th request before the controller could 404 — so "never 429 across LIMIT + 1 requests" proves exclusion without needing real photo fixtures.

**Files:**
- Modify: `tests/Functional/Public/PublicRateLimitTest.php` (add two test methods)

**Interfaces:**
- Consumes: `App\EventListener\PublicRateLimitListener::LIMITED_ROUTES` (the allowlist from Task 1), `self::LIMIT`, and the `clearRateLimiter()` helper.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Add the failing exclusion tests**

Append these two methods to `tests/Functional/Public/PublicRateLimitTest.php` (inside the class, before `clearRateLimiter()`):

```php
    public function testPhotoServeIsNotRateLimited(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->clearRateLimiter();

        $ip = '203.0.113.30';

        // One past the ceiling. The photo doesn't exist (404), but if the route
        // were limited the listener would 429 before the controller 404'd.
        for ($i = 1; $i <= self::LIMIT + 1; $i++) {
            $client->request(
                Request::METHOD_GET,
                '/e/no-such-event/p/999/thumb.jpg',
                [],
                [],
                ['REMOTE_ADDR' => $ip],
            );
            self::assertNotSame(
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

        for ($i = 1; $i <= self::LIMIT + 1; $i++) {
            $client->request(
                Request::METHOD_GET,
                '/e/neighbor-fest/photos/999/neighbor?direction=next',
                [],
                [],
                ['REMOTE_ADDR' => $ip],
            );
            self::assertNotSame(
                Response::HTTP_TOO_MANY_REQUESTS,
                $client->getResponse()->getStatusCode(),
                sprintf('neighbor request %d must never be rate-limited', $i),
            );
        }
    }
```

- [ ] **Step 2: Run the new tests**

Run: `vendor/bin/phpunit tests/Functional/Public/PublicRateLimitTest.php`
Expected: PASS — all four methods green. (Because Task 1's allowlist already excludes these routes, these tests should pass immediately; they lock in that behavior. If either returns 429, the allowlist is wrong.)

- [ ] **Step 3: Run the full quality gate**

Run: `vendor/bin/grumphp run`
Expected: all tasks pass (phpstan L10, phpcs, phpmnd, rector, phpcpd, doctrine:schema:validate, phpunit).

- [ ] **Step 4: Commit**

```bash
git add tests/Functional/Public/PublicRateLimitTest.php
git commit -m "23 - test photo-serve and neighbor routes are exempt from rate limiting"
```

---

## Self-Review

**Spec coverage:**
- Sliding window 120/min limiter → Task 1 Step 1. ✓
- Four `public_event_*` routes covered, by route-name allowlist → Task 1 Step 6 (`LIMITED_ROUTES`). ✓
- Photo-serve + neighbor excluded → Task 1 (allowlist omits them) + Task 2 (tests). ✓
- 429 + `Retry-After` → Task 1 Step 6 (`TooManyRequestsHttpException`) + Step 4 test assertion. ✓
- Limiter runs before controller / 404s count (enumeration) → Task 1 Step 4 `testEnumeration…`. ✓
- Client IP keying respecting trusted proxies → `$request->getClientIp()` in Step 6 (prod `trusted_proxies` already configured; no code change needed). ✓
- Dedicated/clearable cache pool, filesystem storage → Task 1 Step 2 + test `clearRateLimiter()`. ✓
- Per-test isolation via distinct `REMOTE_ADDR` + pool clear → every test method. ✓
- No session coupling (#68) → listener touches only request attributes + cache; noted in Global Constraints. ✓
- phpmnd: numbers in YAML / clamp literal `1` only → Step 6 note. ✓

**Placeholder scan:** none — all code shown in full.

**Type consistency:** `LIMITED_ROUTES` (const), `onRequest()`, `clearRateLimiter()`, `self::LIMIT`, service id `rate_limiter.cache_pool`, and IPs are consistent across Tasks 1 and 2. The limiter is type-hinted `RateLimiterFactoryInterface` throughout.

**Note on `RateLimiterFactoryInterface`:** introduced in Symfony 7.3 and the autowire target in Symfony 8. If `lint:container` (Task 1 Step 3) reports the named binding resolves only to the concrete `Symfony\Component\RateLimiter\RateLimiterFactory`, switch the type-hint in Step 6 to that class — same `create()`/`consume()` API.
