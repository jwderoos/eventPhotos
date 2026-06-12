# Self-Updating QR Display Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a public `/e/{slug}/display` route that renders a full-screen QR code which refreshes its embedded `t` parameter every 60 seconds, so a screenshot of the QR remains a portable "moment bookmark" anchored to the time the QR was captured.

**Architecture:** Extract the existing private photos-URL helper into a `PhotosUrlBuilder` service (single source of truth for the `?t=HH:mm` format). Add two new public routes on `Public\EventController`: a full-screen display page that renders an initial QR, and a tiny SVG-only refresh endpoint the client polls every 60 s. A Stimulus controller swaps the QR's SVG and updates a small "updated HH:mm" indicator client-side using `Intl.DateTimeFormat` anchored to the event's timezone.

**Tech Stack:** PHP 8.5 / Symfony 8 / Doctrine ORM 3 / Endroid QR (already in vendor via `QrCodeRenderer`) / Stimulus / Tailwind+DaisyUI / PHPUnit 13.

**GitHub issue:** #37. Branch: `feature/37-display-qr`. All commit messages must contain `#37` (GrumPHP gate).

---

## Pre-flight

- [ ] **Step 0a: Confirm the worktree is on a feature branch**

Branch name must match `^(feature|hotfix|bugfix|release)/\d+-`. Use `feature/37-display-qr`. If you're on `main`, GrumPHP will refuse the first commit — switch branches before starting.

```bash
git branch --show-current
```

Expected: `feature/37-display-qr`

- [ ] **Step 0b: Sanity-check the baseline**

```bash
vendor/bin/phpunit tests/Functional/Public/EventPhotosGalleryTest.php
```

Expected: green. If the existing photo gallery tests are red, fix that before doing anything else — we need a clean baseline.

---

## File Structure

**Create:**
- `src/Service/Event/PhotosUrlBuilder.php` — single helper that returns `/e/{slug}/photos?t=HH:mm` for a given event + moment. Used by the landing page **and** the new display page.
- `tests/Unit/Service/Event/PhotosUrlBuilderTest.php`
- `templates/public/event/display.html.twig` — full-screen layout intended for projection.
- `assets/controllers/qr_refresh_controller.js` — Stimulus controller; refreshes the QR every 60 s, updates the "Updated HH:mm" element.
- `tests/Functional/Public/EventDisplayTest.php`

**Modify:**
- `src/Controller/Public/EventController.php` — inject `PhotosUrlBuilder`, remove the private `buildPhotosUrl()` method, add `display()` and `displayQr()` actions.

---

## Task 1: Extract `PhotosUrlBuilder` service (no behavior change)

This task is pure refactor. After it, the landing page produces the exact same URL it did before, but via a service that the display page can also use.

**Files:**
- Create: `src/Service/Event/PhotosUrlBuilder.php`
- Create: `tests/Unit/Service/Event/PhotosUrlBuilderTest.php`
- Modify: `src/Controller/Public/EventController.php` (replace `buildPhotosUrl()` + constant `TIME_FORMAT`)

- [ ] **Step 1.1: Write the unit test**

`tests/Unit/Service/Event/PhotosUrlBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Event;

use App\Entity\Event;
use App\Entity\User;
use App\Service\Event\PhotosUrlBuilder;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PhotosUrlBuilderTest extends TestCase
{
    public function testFormatsTAsHourMinuteAndPassesSlug(): void
    {
        $owner = new User('o@example.test', 'O');
        $event = new Event('my-event', 'My Event', new DateTimeImmutable('2026-06-12'), $owner);
        $event->setTimezone('Europe/Amsterdam');

        $when = new DateTimeImmutable('2026-06-12 14:35:42', new DateTimeZone('Europe/Amsterdam'));

        $generator = $this->createMock(UrlGeneratorInterface::class);
        $generator
            ->expects(self::once())
            ->method('generate')
            ->with(
                'public_event_photos',
                ['slug' => 'my-event', 't' => '14:35'],
                UrlGeneratorInterface::ABSOLUTE_PATH,
            )
            ->willReturn('/e/my-event/photos?t=14%3A35');

        $builder = new PhotosUrlBuilder($generator);

        self::assertSame('/e/my-event/photos?t=14%3A35', $builder->build($event, $when));
    }

    public function testAbsoluteUrlFlagProducesAbsoluteUrl(): void
    {
        $owner = new User('o@example.test', 'O');
        $event = new Event('my-event', 'My Event', new DateTimeImmutable('2026-06-12'), $owner);

        $when = new DateTimeImmutable('2026-06-12 09:05:00', new DateTimeZone('UTC'));

        $generator = $this->createMock(UrlGeneratorInterface::class);
        $generator
            ->expects(self::once())
            ->method('generate')
            ->with(
                'public_event_photos',
                ['slug' => 'my-event', 't' => '09:05'],
                UrlGeneratorInterface::ABSOLUTE_URL,
            )
            ->willReturn('https://example.test/e/my-event/photos?t=09%3A05');

        $builder = new PhotosUrlBuilder($generator);

        self::assertSame(
            'https://example.test/e/my-event/photos?t=09%3A05',
            $builder->build($event, $when, absolute: true),
        );
    }
}
```

- [ ] **Step 1.2: Run the test, watch it fail**

```bash
vendor/bin/phpunit tests/Unit/Service/Event/PhotosUrlBuilderTest.php
```

Expected: FAIL — `Class "App\Service\Event\PhotosUrlBuilder" not found`.

- [ ] **Step 1.3: Implement the service**

`src/Service/Event/PhotosUrlBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Event;

use App\Entity\Event;
use DateTimeImmutable;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PhotosUrlBuilder
{
    private const string TIME_FORMAT = 'H:i';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function build(Event $event, DateTimeImmutable $when, bool $absolute = false): string
    {
        return $this->urlGenerator->generate(
            'public_event_photos',
            [
                'slug' => $event->getSlug(),
                't'    => $when->format(self::TIME_FORMAT),
            ],
            $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH,
        );
    }
}
```

- [ ] **Step 1.4: Run the test again, watch it pass**

```bash
vendor/bin/phpunit tests/Unit/Service/Event/PhotosUrlBuilderTest.php
```

Expected: PASS, 2 tests.

- [ ] **Step 1.5: Switch `Public\EventController::landing` to use the service**

Edit `src/Controller/Public/EventController.php`:

- Add `use App\Service\Event\PhotosUrlBuilder;` to the imports.
- Add `private readonly PhotosUrlBuilder $photosUrl,` to the constructor as the fourth dependency (after `PhotoRepository`).
- Delete the `TIME_FORMAT` constant (still used by `resolveTimestamp` — see Step 1.6).
- Delete the private `buildPhotosUrl(Event, DateTimeImmutable)` method.
- In `landing()`, replace `$this->buildPhotosUrl($event, $now)` with `$this->photosUrl->build($event, $now)`.

- [ ] **Step 1.6: Keep `TIME_PATTERN` for input validation but reuse the format constant**

The `resolveTimestamp()` method still parses `H:i` input. To keep a single source of truth, leave `TIME_PATTERN` where it is (input validation, not formatting) but delete `TIME_FORMAT` from the controller — formatting lives in `PhotosUrlBuilder` now.

Final controller diff for verification (file should look like this after the edit; partial view):

```php
public function __construct(
    private readonly EventRepository $events,
    private readonly ClockInterface $clock,
    private readonly PhotoRepository $photos,
    private readonly PhotosUrlBuilder $photosUrl,
) {
}
```

```php
public function landing(string $slug): Response
{
    $event = $this->resolve($slug);
    $now   = $this->nowInEventTimezone($event);

    return $this->render('public/event/landing.html.twig', [
        'event'         => $event,
        'now'           => $now,
        'windowMinutes' => $event->resolveWindowMinutes(),
        'photosUrl'     => $this->photosUrl->build($event, $now),
    ]);
}
```

- [ ] **Step 1.7: Run all public + unit tests to confirm landing still works**

```bash
vendor/bin/phpunit tests/Unit/Service/Event/ tests/Functional/Public/
```

Expected: green, including pre-existing `EventPhotosGalleryTest` and any landing tests.

- [ ] **Step 1.8: PHPStan + PHPCS**

```bash
vendor/bin/phpstan analyse src/Service/Event/PhotosUrlBuilder.php src/Controller/Public/EventController.php tests/Unit/Service/Event/PhotosUrlBuilderTest.php
vendor/bin/phpcs src/Service/Event/PhotosUrlBuilder.php src/Controller/Public/EventController.php tests/Unit/Service/Event/PhotosUrlBuilderTest.php
```

Expected: both clean.

- [ ] **Step 1.9: Commit**

```bash
git add src/Service/Event/PhotosUrlBuilder.php tests/Unit/Service/Event/PhotosUrlBuilderTest.php src/Controller/Public/EventController.php
git commit -m "37 - extract PhotosUrlBuilder service for #37"
```

---

## Task 2: Add `/e/{slug}/display` route with server-rendered QR

This task ships a working (non-refreshing) display page. The QR is server-rendered exactly once from current server time; refreshing the browser would update it. The client-side refresh comes in Task 4.

**Files:**
- Create: `templates/public/event/display.html.twig`
- Create: `tests/Functional/Public/EventDisplayTest.php`
- Modify: `src/Controller/Public/EventController.php`

- [ ] **Step 2.1: Write the functional test for the display page**

`tests/Functional/Public/EventDisplayTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EventDisplayTest extends WebTestCase
{
    public function testDisplayPageRendersQrEncodingPhotosUrlInCurrentFormat(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $owner = new User('display-owner@example.test', 'O');
        $owner->setPassword('x');
        $em->persist($owner);

        $event = new Event(
            'big-night',
            'Big Night',
            new DateTimeImmutable('2026-06-12'),
            $owner,
        );
        $event->setTimezone('Europe/Amsterdam');
        $em->persist($event);
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/big-night/display');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');

        $html = (string) $client->getResponse()->getContent();

        // It is a full-screen display page (no public chrome).
        $this->assertStringContainsString('Big Night', $html);

        // The QR's embedded URL targets public_event_photos with `t=HH:mm` and no `w`.
        $this->assertMatchesRegularExpression(
            '#/e/big-night/photos\?t=\d{2}%3A\d{2}#',
            $html,
            'QR SVG should encode the photos URL with t in HH:mm format',
        );
        $this->assertStringNotContainsString('w=', $html, '`w` should not appear in the embedded URL');

        // SVG payload is inlined.
        $this->assertStringContainsString('<svg', $html);
    }

    public function testDisplayPage404sForUnknownSlug(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/e/does-not-exist/display');
        $this->assertResponseStatusCodeSame(404);
    }
}
```

- [ ] **Step 2.2: Run the test, watch it fail**

```bash
vendor/bin/phpunit tests/Functional/Public/EventDisplayTest.php
```

Expected: FAIL — 404 on `/e/big-night/display` (route not defined yet).

- [ ] **Step 2.3: Add controller action**

Edit `src/Controller/Public/EventController.php`:

- Add imports:
  ```php
  use App\Service\QrCodeRenderer;
  use League\Flysystem\FilesystemException;
  use League\Flysystem\FilesystemOperator;
  use Psr\Log\LoggerInterface;
  use Symfony\Component\DependencyInjection\Attribute\Autowire;
  ```
- Extend the constructor:
  ```php
  public function __construct(
      private readonly EventRepository $events,
      private readonly ClockInterface $clock,
      private readonly PhotoRepository $photos,
      private readonly PhotosUrlBuilder $photosUrl,
      private readonly QrCodeRenderer $qr,
      #[Autowire(service: 'event_logos_storage')]
      private readonly FilesystemOperator $eventLogosStorage,
      private readonly LoggerInterface $logger,
  ) {
  }
  ```
- Add the `display` action after `photos()`:
  ```php
  #[Route(
      '/e/{slug}/display',
      name: 'public_event_display',
      requirements: ['slug' => '[a-z0-9-]+'],
      methods: ['GET'],
  )]
  public function display(string $slug): Response
  {
      $event = $this->resolve($slug);
      $now   = $this->nowInEventTimezone($event);

      return $this->render('public/event/display.html.twig', [
          'event'     => $event,
          'now'       => $now,
          'photosUrl' => $this->photosUrl->build($event, $now, absolute: true),
          'qrSvg'     => $this->qr->svg(
              $this->photosUrl->build($event, $now, absolute: true),
              $this->readLogoBytes($event),
              size: 720,
          ),
      ]);
  }
  ```
- Add a private helper at the bottom of the class (mirrors `Admin\EventController::readLogoBytes` — small duplication is intentional; YAGNI on shared extraction since the two sites use it and that's it):
  ```php
  private function readLogoBytes(Event $event): ?string
  {
      $filename = $event->getLogoFilename();
      if ($filename === null) {
          return null;
      }

      try {
          return $this->eventLogosStorage->read($filename);
      } catch (FilesystemException $filesystemException) {
          $this->logger->warning('Failed to read event logo; rendering QR without it', [
              'event_id'  => $event->getId(),
              'filename'  => $filename,
              'exception' => $filesystemException,
          ]);
          return null;
      }
  }
  ```

`QrCodeRenderer::svg()` accepts an optional `size` named arg — we pass `720` so the SVG renders crisply at projection scale.

- [ ] **Step 2.4: Create the display template**

`templates/public/event/display.html.twig`:

```twig
<!DOCTYPE html>
<html lang="en" data-theme="silk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ event.name }} — Display</title>
    {{ importmap('app') }}
</head>
<body class="min-h-screen bg-base-100 text-base-content">
    <main
        {{ stimulus_controller('qr-refresh', {
            endpoint: path('public_event_display_qr', {slug: event.slug}),
            timezone: event.timezone,
            intervalMs: 60000,
        }) }}
        class="flex min-h-screen flex-col items-center justify-center gap-6 px-8 py-10"
    >
        <h1 class="text-5xl font-semibold tracking-tight text-center">{{ event.name }}</h1>

        <div
            {{ stimulus_target('qr-refresh', 'qr') }}
            data-photos-url="{{ photosUrl }}"
            class="w-[min(80vh,80vw)] aspect-square bg-base-100 rounded-2xl shadow-xl p-6 flex items-center justify-center"
        >
            {{ qrSvg|raw }}
        </div>

        <p class="text-base text-base-content/60">
            Scan to see your photos · Updated
            <time {{ stimulus_target('qr-refresh', 'updated') }} datetime="{{ now|date('c') }}">
                {{ now|date('H:i', event.timezone) }}
            </time>
        </p>
    </main>
</body>
</html>
```

Notes for the implementer:
- The display page does **not** extend `public/_base.html.twig` — the spec calls for a full-screen treatment without site chrome.
- `w-[min(80vh,80vw)] aspect-square` keeps the QR square and fills whichever screen dimension is smaller — so the QR is huge on a 16:9 projector.
- The Stimulus targets `qr` and `updated` are referenced by `qr_refresh_controller.js` in Task 4.
- The `path('public_event_display_qr', ...)` reference targets the route added in Task 3 — Symfony will fail to render this template until Task 3 lands. **That is intentional**: doing Task 3 before Task 4 keeps the tests honest. Verify Task 2's tests by temporarily using `path('public_event_landing', {slug: event.slug})` as a placeholder if you must commit Task 2 in isolation. If you commit Task 2 and Task 3 together, no placeholder is needed.

**Decision**: commit Task 2 + Task 3 together. The plan keeps them as separate task headings for clarity, but the commit boundary is "Task 2 + Task 3 in one commit".

- [ ] **Step 2.5: Run the functional test — expect it to still fail (route helper points to Task 3)**

```bash
vendor/bin/phpunit tests/Functional/Public/EventDisplayTest.php
```

Expected: FAIL with `Route "public_event_display_qr" does not exist.`

Proceed to Task 3 immediately. No commit yet.

---

## Task 3: Add `/e/{slug}/display/qr.svg` refresh endpoint

Tiny endpoint: returns SVG bytes of the current QR, anchored to *current server time in event timezone*. The Stimulus controller (Task 4) calls this every 60 s. The endpoint is cacheable for a *very short* time (a few seconds) so that two clients refreshing within the same minute don't double-render, but not so long that the `t` becomes stale.

Actually keep it simpler: `Cache-Control: no-store`. Re-rendering Endroid QR is cheap (~5 ms). YAGNI on caching.

**Files:**
- Modify: `src/Controller/Public/EventController.php`
- Modify: `tests/Functional/Public/EventDisplayTest.php`

- [ ] **Step 3.1: Extend the functional test**

Add two more methods to `tests/Functional/Public/EventDisplayTest.php`:

```php
public function testRefreshEndpointReturnsSvgWithFreshT(): void
{
    $client    = self::createClient();
    $container = self::getContainer();
    /** @var EntityManagerInterface $em */
    $em = $container->get(EntityManagerInterface::class);

    $owner = new User('refresh-owner@example.test', 'O');
    $owner->setPassword('x');
    $em->persist($owner);

    $event = new Event(
        'refresh-night',
        'Refresh Night',
        new DateTimeImmutable('2026-06-12'),
        $owner,
    );
    $event->setTimezone('Europe/Amsterdam');
    $em->persist($event);
    $em->flush();

    $client->request(Request::METHOD_GET, '/e/refresh-night/display/qr.svg');

    $this->assertResponseIsSuccessful();
    $this->assertResponseHeaderSame('Content-Type', 'image/svg+xml');
    $this->assertResponseHeaderSame('Cache-Control', 'no-store, private');

    $svg = (string) $client->getResponse()->getContent();
    $this->assertStringContainsString('<svg', $svg);

    // SVG body contains an absolute URL targeting public_event_photos with t=HH:mm.
    $this->assertMatchesRegularExpression(
        '#https?://[^"]*/e/refresh-night/photos\?t=\d{2}%3A\d{2}#',
        $svg,
    );
    $this->assertStringNotContainsString('w=', $svg);
}

public function testRefreshEndpoint404sForUnknownSlug(): void
{
    $client = self::createClient();
    $client->request(Request::METHOD_GET, '/e/does-not-exist/display/qr.svg');
    $this->assertResponseStatusCodeSame(404);
}
```

- [ ] **Step 3.2: Run the test, watch it fail**

```bash
vendor/bin/phpunit tests/Functional/Public/EventDisplayTest.php
```

Expected: FAIL — 404 on `/e/refresh-night/display/qr.svg`.

- [ ] **Step 3.3: Add the action**

Edit `src/Controller/Public/EventController.php`. Add this action after `display()`:

```php
#[Route(
    '/e/{slug}/display/qr.svg',
    name: 'public_event_display_qr',
    requirements: ['slug' => '[a-z0-9-]+'],
    methods: ['GET'],
)]
public function displayQr(string $slug): Response
{
    $event = $this->resolve($slug);
    $now   = $this->nowInEventTimezone($event);

    $svg = $this->qr->svg(
        $this->photosUrl->build($event, $now, absolute: true),
        $this->readLogoBytes($event),
        size: 720,
    );

    $response = new Response($svg);
    $response->headers->set('Content-Type', 'image/svg+xml');
    $response->headers->set('Cache-Control', 'no-store, private');

    return $response;
}
```

- [ ] **Step 3.4: Run BOTH display tests, watch them pass**

```bash
vendor/bin/phpunit tests/Functional/Public/EventDisplayTest.php
```

Expected: PASS, 4 tests.

- [ ] **Step 3.5: PHPStan + PHPCS on changed files**

```bash
vendor/bin/phpstan analyse src/Controller/Public/EventController.php tests/Functional/Public/EventDisplayTest.php templates/public/event/display.html.twig
vendor/bin/phpcs src/Controller/Public/EventController.php tests/Functional/Public/EventDisplayTest.php
```

Expected: both clean. (PHPStan does not analyse twig; ignore the template if PHPStan complains about the path.)

- [ ] **Step 3.6: Commit Tasks 2 + 3 together**

```bash
git add src/Controller/Public/EventController.php templates/public/event/display.html.twig tests/Functional/Public/EventDisplayTest.php
git commit -m "37 - add /e/{slug}/display page with QR refresh endpoint - refs #37"
```

---

## Task 4: Stimulus controller to refresh the QR every 60 s

**Files:**
- Create: `assets/controllers/qr_refresh_controller.js`

- [ ] **Step 4.1: Write the controller**

`assets/controllers/qr_refresh_controller.js`:

```javascript
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['qr', 'updated'];

    static values = {
        endpoint: String,
        timezone: String,
        intervalMs: { type: Number, default: 60000 },
    };

    connect() {
        this.boundRefresh = this.refresh.bind(this);
        this.timer = setInterval(this.boundRefresh, this.intervalMsValue);
    }

    disconnect() {
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
    }

    async refresh() {
        try {
            const response = await fetch(this.endpointValue, {
                headers: { Accept: 'image/svg+xml' },
                cache: 'no-store',
            });
            if (!response.ok) {
                return;
            }
            const svg = await response.text();
            if (this.hasQrTarget) {
                this.qrTarget.innerHTML = svg;
            }
            if (this.hasUpdatedTarget) {
                this.updateTimestamp();
            }
        } catch (e) {
            // Silent: a failed refresh just means the existing QR stays on screen.
            // The next interval tick will try again.
        }
    }

    updateTimestamp() {
        const now = new Date();
        const formatter = new Intl.DateTimeFormat('en-GB', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
            timeZone: this.timezoneValue || undefined,
        });
        this.updatedTarget.textContent = formatter.format(now);
        this.updatedTarget.setAttribute('datetime', now.toISOString());
    }
}
```

- [ ] **Step 4.2: Verify Stimulus auto-registration**

The repository uses Stimulus auto-registration via `assets/controllers/`. Confirm no manual registry change is needed:

```bash
grep -n "controllers" assets/stimulus_bootstrap.js
```

Expected: the bootstrap file glob-loads `controllers/`. If not, register manually per the existing convention. (As of writing the convention is symfony/stimulus-bundle auto-discovery — no change needed.)

- [ ] **Step 4.3: Manual smoke test in the browser**

Plan-time decision: this step is mandatory before the commit. The Stimulus controller is JS — type checks don't validate refresh behavior.

```bash
docker compose up -d
docker compose logs -f php-fpm   # optional, in another tab
# In yet another tab, start the Tailwind watcher if not running:
docker compose logs -f tailwind  # confirm it's compiling
```

In the browser:

1. Visit `http://localhost:8080/admin` and sign in as a user who owns at least one event.
2. Note the slug (e.g., from `/admin/events`).
3. Visit `http://localhost:8080/e/<slug>/display`.
4. Open DevTools → Network tab.
5. Confirm: initial page paints with a large QR + "Updated HH:mm".
6. Wait ~60 s. Confirm: a `qr.svg` request appears in Network; the QR visibly swaps without a page reload; the "Updated HH:mm" updates.
7. Phone-scan the displayed QR. Confirm it opens `/e/<slug>/photos?t=HH:mm` with `HH:mm` matching what was on the screen when scanned.
8. Take a screenshot of the screen. Wait two minutes. Phone-scan the **screenshot**. Confirm: the photos page opens with `t` set to the original captured time (the "moment bookmark" property — this is the headline acceptance criterion).

If any step fails: do **not** commit. Debug. The most likely failure points: Stimulus controller not registered (controller name mismatch), endpoint returning the wrong content-type (refresh swap won't work), Tailwind class typo (layout doesn't fill screen).

- [ ] **Step 4.4: Commit**

```bash
git add assets/controllers/qr_refresh_controller.js
git commit -m "37 - add qr_refresh stimulus controller for live QR display - refs #37"
```

---

## Task 5: Full GrumPHP gate before opening the PR

- [ ] **Step 5.1: Run the full suite**

```bash
vendor/bin/grumphp run
```

Expected: all green. If anything red:
- `doctrine:schema:validate` failures: there shouldn't be any (no schema changes in this PR) — if it's red, you migrated something accidentally; revert.
- `phpstan` level 10: most likely culprit is type narrowing in the new controller actions.
- `phpcs`: line length / spacing in new files.
- `rector`: usually offers `--dry-run` hints; apply them if reasonable.

- [ ] **Step 5.2: Open PR**

Title: `37 - self-updating QR display for venue screens`
Body (heredoc):

```
## Summary
- Adds `/e/{slug}/display`: full-screen QR for projection.
- Adds `/e/{slug}/display/qr.svg`: tiny refresh endpoint; SVG with `Cache-Control: no-store`.
- Stimulus `qr_refresh` controller swaps the QR every 60 s; no full reload.
- Extracts `PhotosUrlBuilder` so landing + display share the URL format (closes the "one place to swap" requirement).

Closes #37.

## Test plan
- [ ] `vendor/bin/grumphp run` clean
- [ ] `vendor/bin/phpunit tests/Functional/Public/EventDisplayTest.php` green
- [ ] Manual: visit `/e/<slug>/display`, screenshot, scan screenshot 2 min later → `t` reflects screenshot moment
```

---

## Self-review checklist

**1. Spec coverage** (issue #37 acceptance criteria):

- ✅ `GET /e/{slug}/display` returns 200 with QR → Task 2 (`testDisplayPageRendersQrEncodingPhotosUrlInCurrentFormat`).
- ✅ QR encodes `/e/{slug}/photos?t=<now>` in landing-page format, anchored to event timezone → Tasks 2 + 3 use `PhotosUrlBuilder` which formats `H:i` in event tz (controller passes `nowInEventTimezone($event)`).
- ✅ After ~60 s the QR refreshes client-side, no full reload, new `t` is ~60 s later → Task 4 Stimulus controller; smoke-tested in Step 4.3.
- ✅ Phone-photograph → scan later → original captured moment → Task 4 Step 4.3 step 8 (the explicit bookmark test).
- ✅ Legible on 1080p across a room → Task 2 template uses `w-[min(80vh,80vw)] aspect-square` and `size: 720` on `QrCodeRenderer::svg`.
- ✅ Functional tests cover: 200, embedded URL targets `public_event_photos`, `t` matches landing format, `w` absent → Tasks 2 + 3.
- ✅ Shared helper for the URL format → Task 1 (`PhotosUrlBuilder`).

**2. Placeholder scan**: every code step contains the full code; no TBD/TODO/"similar to". The `readLogoBytes` helper is duplicated from `Admin\EventController` intentionally (~15 lines, two call sites total) — flagged in Step 2.3.

**3. Type consistency**: `PhotosUrlBuilder::build()` signature `(Event, DateTimeImmutable, bool $absolute = false): string` used consistently in landing, display, and displayQr.

**4. Commit hygiene**: branch `feature/37-display-qr` matches GrumPHP regex; every commit message contains `#37` (the `37 - …` prefix already satisfies the pattern, but `refs #37`/`closes #37` is added to commits 2 and 3 for clarity).
