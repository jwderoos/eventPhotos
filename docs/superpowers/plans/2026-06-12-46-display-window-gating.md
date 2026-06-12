# Display Mode Window Gating — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gate `/e/{slug}/display` by event window — three states (Pre/Live/Post), with the page self-healing across `startsAt`/`endsAt` transitions via a polling header check.

**Architecture:** A new string-backed enum `EventDisplayState` plus a pure `Event::computeDisplayState(DateTimeImmutable $now)` method classify the moment. `display()` and `displayQr()` both branch on it; the refresh endpoint always carries an `X-Display-State` header (and an `X-Photos-Url` header for Pre/Live), so the Stimulus controller can detect state changes and `window.location.reload()` to swap layouts without operator action.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3, `symfony/clock` (MockClock for functional tests), Stimulus, PHPUnit 13.

**Spec:** `docs/superpowers/specs/2026-06-12-46-display-event-window-gating-design.md`

**Branch:** `feature/46-display-window-gating` (created in Task 0).

**Project conventions reminders:**
- GrumPHP gates: branch name regex `^(feature|hotfix|bugfix|release)/\d+-`; every commit message must contain `#46` or start with `46 -`; phpstan level 10; phpcs PSR-12; phpmnd (no magic numbers in `src/`); phpcpd; rector; `doctrine:schema:validate` (no schema change here, but the gate still runs).
- **Per project memory:** the user runs `git commit` themselves. Each task's "stage and commit" step lists what to `git add` and includes the suggested commit message — pause and let the user commit.
- Host PHP only for `bin/console`, `composer`, `vendor/bin/*`. Stack stays in Docker.

---

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `src/Entity/EventDisplayState.php` | Create | String-backed enum (`pre`/`live`/`post`) |
| `src/Entity/Event.php` | Modify | Add `computeDisplayState(DateTimeImmutable): EventDisplayState` |
| `tests/Unit/Entity/EventTest.php` | Modify | Unit tests for `computeDisplayState` incl. boundaries and DST cross |
| `config/services.yaml` | Modify | Register `MockClock` as `ClockInterface` under `when@test` |
| `src/Controller/Public/EventController.php` | Modify | State-aware `display()` and `displayQr()` with `X-Display-State`/`X-Photos-Url` headers |
| `templates/public/event/display.html.twig` | Modify | Three branches (pre/live/post), visible URL link in pre/live |
| `assets/controllers/qr_refresh_controller.js` | Modify | New `state` value + `photosUrl` target; reload-on-mismatch; URL swap on each tick |
| `tests/Functional/Public/EventDisplayTest.php` | Modify | Tests for the three states (page + refresh endpoint) + boundary cases |

No migration, no new routes.

---

## Task 0: Create feature branch

**Files:** none

- [ ] **Step 0.1: Create and check out the feature branch from up-to-date `main`**

```bash
git checkout main
git pull --ff-only
git checkout -b feature/46-display-window-gating
```

Expected: new branch active, working tree clean.

---

## Task 1: `EventDisplayState` enum + `Event::computeDisplayState`

**Files:**
- Create: `src/Entity/EventDisplayState.php`
- Modify: `src/Entity/Event.php` (add method near `resolveWindowMinutes`)
- Modify: `tests/Unit/Entity/EventTest.php` (add tests; do not touch existing ones)

- [ ] **Step 1.1: Write the failing unit test**

Append to `tests/Unit/Entity/EventTest.php` (add `use App\Entity\EventDisplayState;` near the existing `use` block):

```php
public function testComputeDisplayStateReturnsPreBeforeStart(): void
{
    $event = new Event(
        'e',
        'E',
        new DateTimeImmutable('2026-07-15 10:00', new DateTimeZone('Europe/Amsterdam')),
        new DateTimeImmutable('2026-07-15 14:00', new DateTimeZone('Europe/Amsterdam')),
        new User('o@x', 'Owner'),
    );

    $state = $event->computeDisplayState(
        new DateTimeImmutable('2026-07-15 09:59:59', new DateTimeZone('Europe/Amsterdam')),
    );

    $this->assertSame(EventDisplayState::Pre, $state);
}

public function testComputeDisplayStateReturnsLiveAtStartsAtBoundary(): void
{
    $event = new Event(
        'e',
        'E',
        new DateTimeImmutable('2026-07-15 10:00', new DateTimeZone('Europe/Amsterdam')),
        new DateTimeImmutable('2026-07-15 14:00', new DateTimeZone('Europe/Amsterdam')),
        new User('o@x', 'Owner'),
    );

    $state = $event->computeDisplayState(
        new DateTimeImmutable('2026-07-15 10:00:00', new DateTimeZone('Europe/Amsterdam')),
    );

    $this->assertSame(EventDisplayState::Live, $state);
}

public function testComputeDisplayStateReturnsLiveInsideWindow(): void
{
    $event = new Event(
        'e',
        'E',
        new DateTimeImmutable('2026-07-15 10:00', new DateTimeZone('Europe/Amsterdam')),
        new DateTimeImmutable('2026-07-15 14:00', new DateTimeZone('Europe/Amsterdam')),
        new User('o@x', 'Owner'),
    );

    $state = $event->computeDisplayState(
        new DateTimeImmutable('2026-07-15 12:00:00', new DateTimeZone('Europe/Amsterdam')),
    );

    $this->assertSame(EventDisplayState::Live, $state);
}

public function testComputeDisplayStateReturnsLiveAtEndsAtBoundary(): void
{
    $event = new Event(
        'e',
        'E',
        new DateTimeImmutable('2026-07-15 10:00', new DateTimeZone('Europe/Amsterdam')),
        new DateTimeImmutable('2026-07-15 14:00', new DateTimeZone('Europe/Amsterdam')),
        new User('o@x', 'Owner'),
    );

    $state = $event->computeDisplayState(
        new DateTimeImmutable('2026-07-15 14:00:00', new DateTimeZone('Europe/Amsterdam')),
    );

    $this->assertSame(EventDisplayState::Live, $state);
}

public function testComputeDisplayStateReturnsPostAfterEnd(): void
{
    $event = new Event(
        'e',
        'E',
        new DateTimeImmutable('2026-07-15 10:00', new DateTimeZone('Europe/Amsterdam')),
        new DateTimeImmutable('2026-07-15 14:00', new DateTimeZone('Europe/Amsterdam')),
        new User('o@x', 'Owner'),
    );

    $state = $event->computeDisplayState(
        new DateTimeImmutable('2026-07-15 14:00:01', new DateTimeZone('Europe/Amsterdam')),
    );

    $this->assertSame(EventDisplayState::Post, $state);
}

public function testComputeDisplayStateHandlesDstAutumnFallBack(): void
{
    // Autumn DST in Europe/Amsterdam 2026: 03:00 CEST -> 02:00 CET on Sun 25 Oct.
    $event = new Event(
        'e',
        'E',
        new DateTimeImmutable('2026-10-25 02:30', new DateTimeZone('Europe/Amsterdam')),
        new DateTimeImmutable('2026-10-25 04:00', new DateTimeZone('Europe/Amsterdam')),
        new User('o@x', 'Owner'),
    );

    // DateTimeImmutable comparison must use absolute instants, not wall-clock strings.
    $beforeStart = new DateTimeImmutable('2026-10-25 02:29', new DateTimeZone('Europe/Amsterdam'));
    $insideAfterShift = new DateTimeImmutable('2026-10-25 02:30', new DateTimeZone('Europe/Amsterdam'));

    $this->assertSame(EventDisplayState::Pre, $event->computeDisplayState($beforeStart));
    $this->assertSame(EventDisplayState::Live, $event->computeDisplayState($insideAfterShift));
}
```

- [ ] **Step 1.2: Run the new tests to verify they fail**

```bash
vendor/bin/phpunit --filter computeDisplayState tests/Unit/Entity/EventTest.php
```

Expected: failures with messages like "Class \"App\\Entity\\EventDisplayState\" not found" or "Call to undefined method App\\Entity\\Event::computeDisplayState".

- [ ] **Step 1.3: Create the enum**

Create `src/Entity/EventDisplayState.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

enum EventDisplayState: string
{
    case Pre  = 'pre';
    case Live = 'live';
    case Post = 'post';
}
```

- [ ] **Step 1.4: Add the method to `Event`**

In `src/Entity/Event.php`, add the method directly after `resolveWindowMinutes()` (around line 173). No new `use` needed — `DateTimeImmutable` is already imported; add `use App\Entity\EventDisplayState;` if the IDE doesn't auto-add (same namespace, so an unqualified reference works).

```php
public function computeDisplayState(DateTimeImmutable $now): EventDisplayState
{
    if ($now < $this->startsAt) {
        return EventDisplayState::Pre;
    }

    if ($now > $this->endsAt) {
        return EventDisplayState::Post;
    }

    return EventDisplayState::Live;
}
```

- [ ] **Step 1.5: Run the new tests to verify they pass**

```bash
vendor/bin/phpunit --filter computeDisplayState tests/Unit/Entity/EventTest.php
```

Expected: 6 tests pass.

- [ ] **Step 1.6: Run the full unit test bundle for the entity to guard against regressions**

```bash
vendor/bin/phpunit tests/Unit/Entity/EventTest.php
```

Expected: all existing + new tests pass.

- [ ] **Step 1.7: Stage and let the user commit**

```bash
git add src/Entity/EventDisplayState.php src/Entity/Event.php tests/Unit/Entity/EventTest.php
```

Suggested commit message (user runs):
```
46 - add EventDisplayState enum and Event::computeDisplayState
```

---

## Task 2: Wire `MockClock` in the test environment

**Files:**
- Modify: `config/services.yaml` (add a `when@test` block at the bottom)

The functional tests in later tasks need deterministic `now` for boundary assertions. Replace the production `clock` service with `Symfony\Component\Clock\MockClock` only in the test env.

- [ ] **Step 2.1: Append the test-env block to `config/services.yaml`**

Append to `config/services.yaml`:

```yaml
when@test:
    services:
        Symfony\Component\Clock\MockClock:
            arguments:
                $now: '2026-06-12 12:00:00'
                $timezone: 'UTC'

        Symfony\Component\Clock\ClockInterface:
            alias: Symfony\Component\Clock\MockClock
```

This makes `ClockInterface` resolve to a `MockClock` whose initial `now` is `2026-06-12 12:00:00 UTC`. Tests that need a different moment call `->modify('@<unix-ts>')` or `->modify('<datetime>')` on the resolved service via `static::getContainer()->get(ClockInterface::class)`.

- [ ] **Step 2.2: Sanity-check the existing functional suite still passes**

```bash
vendor/bin/phpunit tests/Functional
```

Expected: all green. (Existing `EventDisplayTest::testDisplayPageRendersQrEncodingPhotosUrlInCurrentFormat` creates an event whose window is `2026-06-12 10:00 — 14:00`. With MockClock at `2026-06-12 12:00:00 UTC` and the default `date.timezone`, the controller's `nowInEventTimezone()` rebases UTC into `Europe/Amsterdam` — the resulting `now` falls inside the event window, so this test stays Live.)

If a test breaks because it implicitly relied on real `now`, fix it by either calling `->modify(...)` to position the clock or by adjusting the event window in the fixture. Do **not** revert MockClock.

- [ ] **Step 2.3: Stage and let the user commit**

```bash
git add config/services.yaml
```

Suggested commit message:
```
46 - wire MockClock as ClockInterface in test env
```

---

## Task 3: Pre-event branch on `display()`

**Files:**
- Modify: `src/Controller/Public/EventController.php`
- Modify: `templates/public/event/display.html.twig`
- Modify: `tests/Functional/Public/EventDisplayTest.php`

Implement pre-event rendering first; live and post are subsequent tasks. We want the test to drive each branch independently.

- [ ] **Step 3.1: Write the failing functional test for Pre state**

Append a method to `tests/Functional/Public/EventDisplayTest.php` (add `use App\Entity\EventDisplayState;` and `use Symfony\Component\Clock\ClockInterface;` and `use Symfony\Component\Clock\MockClock;` to the imports):

```php
public function testDisplayPageInPreEventStateRendersStaticQrAnchoredToStartsAt(): void
{
    $client    = self::createClient();
    $container = self::getContainer();
    /** @var EntityManagerInterface $em */
    $em = $container->get(EntityManagerInterface::class);
    /** @var MockClock $clock */
    $clock = $container->get(ClockInterface::class);

    $owner = new User('pre-owner@example.test', 'O');
    $owner->setPassword('x');
    $em->persist($owner);

    $event = new Event(
        'pre-night',
        'Pre Night',
        new DateTimeImmutable('2026-07-15 19:00', new \DateTimeZone('Europe/Amsterdam')),
        new DateTimeImmutable('2026-07-15 23:00', new \DateTimeZone('Europe/Amsterdam')),
        $owner,
    );
    $event->setTimezone('Europe/Amsterdam');
    $em->persist($event);
    $em->flush();

    // Park the clock comfortably before the start.
    $clock->modify('2026-07-15 16:00:00');

    $client->request(Request::METHOD_GET, '/e/pre-night/display');

    $this->assertResponseIsSuccessful();
    $html = (string) $client->getResponse()->getContent();

    $this->assertStringContainsString('Pre Night', $html);
    $this->assertStringContainsString('data-qr-refresh-state-value="pre"', $html);
    // The visible/static URL link encodes t=19:00 (event TZ).
    $this->assertMatchesRegularExpression(
        '#href="https?://[^"]+/e/pre-night/photos\?t=19%3A00"#',
        $html,
    );
    // QR is present in pre state (just static).
    $this->assertStringContainsString('<svg', $html);
    // "Starts 19:00" annotation.
    $this->assertMatchesRegularExpression('#Starts\s*<time[^>]*>19:00</time>#', $html);
}
```

- [ ] **Step 3.2: Run the new test to verify it fails**

```bash
vendor/bin/phpunit --filter testDisplayPageInPreEventStateRendersStaticQrAnchoredToStartsAt tests/Functional/Public/EventDisplayTest.php
```

Expected: failure (page still renders live layout for any time).

- [ ] **Step 3.3: Modify the controller — extract a helper and branch in `display()`**

In `src/Controller/Public/EventController.php`:

Add `use App\Entity\EventDisplayState;` to the imports.

Replace the body of `display()` (current lines 92–107) with:

```php
public function display(string $slug): Response
{
    $event           = $this->resolve($slug);
    [$now, $state]   = $this->resolveNowAndState($event);
    $photosUrl       = $this->buildPhotosUrlForState($event, $now, $state);
    $qrSvg           = $photosUrl === null
        ? null
        : $this->qr->svg(
            $photosUrl,
            $this->readLogoBytes($event),
            size: self::DISPLAY_QR_SIZE,
        );

    return $this->render('public/event/display.html.twig', [
        'event'     => $event,
        'now'       => $now,
        'state'     => $state,
        'photosUrl' => $photosUrl,
        'qrSvg'     => $qrSvg,
    ]);
}
```

Add two private helpers near the bottom of the class (above `readLogoBytes` is fine):

```php
/**
 * @return array{0: DateTimeImmutable, 1: EventDisplayState}
 */
private function resolveNowAndState(Event $event): array
{
    $now = $this->nowInEventTimezone($event);

    return [$now, $event->computeDisplayState($now)];
}

private function buildPhotosUrlForState(
    Event $event,
    DateTimeImmutable $now,
    EventDisplayState $state,
): ?string {
    return match ($state) {
        EventDisplayState::Pre  => $this->photosUrl->build(
            $event,
            $event->getStartsAt()->setTimezone(new DateTimeZone($event->getTimezone())),
            absolute: true,
        ),
        EventDisplayState::Live => $this->photosUrl->build($event, $now, absolute: true),
        EventDisplayState::Post => null,
    };
}
```

- [ ] **Step 3.4: Rewrite `templates/public/event/display.html.twig` with all three branches**

Replace the entire file contents with:

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
        {% if state.value != 'post' %}
        {{ stimulus_controller('qr-refresh', {
            endpoint: path('public_event_display_qr', {slug: event.slug}),
            timezone: event.timezone,
            state: state.value,
            intervalMs: 60000,
        }) }}
        {% endif %}
        class="flex min-h-screen flex-col items-center justify-center gap-6 px-8 py-10"
    >
        <h1 class="text-5xl font-semibold tracking-tight text-center">{{ event.name }}</h1>

        {% if state.value == 'post' %}
            <p class="text-2xl text-base-content/70">This event has ended.</p>
        {% else %}
            <div
                {{ stimulus_target('qr-refresh', 'qr') }}
                class="w-[min(80vh,80vw)] aspect-square bg-base-100 rounded-2xl shadow-xl p-6 flex items-center justify-center"
            >
                {{ qrSvg|raw }}
            </div>

            <p class="text-base text-base-content/60 text-center">
                {% if state.value == 'pre' %}
                    Starts <time datetime="{{ event.startsAt|date('c') }}">{{ event.startsAt|date('H:i', event.timezone) }}</time>
                {% else %}
                    Updated
                    <time {{ stimulus_target('qr-refresh', 'updated') }} datetime="{{ now|date('c') }}">
                        {{ now|date('H:i', event.timezone) }}
                    </time>
                {% endif %}
                ·
                <a
                    {{ stimulus_target('qr-refresh', 'photosUrl') }}
                    href="{{ photosUrl }}"
                    class="underline decoration-dotted underline-offset-2"
                >{{ photosUrl }}</a>
            </p>
        {% endif %}
    </main>
</body>
</html>
```

Notes:
- `stimulus_controller('qr-refresh', { state: state.value, ... })` produces `data-qr-refresh-state-value="<value>"` (the assertion the test checks).
- The Stimulus controller block is omitted entirely in post state (no polling, no data attributes).
- The Twig escaping for `?t=19:00` will encode `:` as `%3A` in `href`, matching the regex in the test.

- [ ] **Step 3.5: Run the Pre test again**

```bash
vendor/bin/phpunit --filter testDisplayPageInPreEventStateRendersStaticQrAnchoredToStartsAt tests/Functional/Public/EventDisplayTest.php
```

Expected: PASS.

- [ ] **Step 3.6: Run the full `EventDisplayTest` to confirm the existing live tests still pass**

```bash
vendor/bin/phpunit tests/Functional/Public/EventDisplayTest.php
```

Expected: existing tests still green. (They construct events whose windows include the MockClock default `2026-06-12 12:00:00 UTC` rebased into `Europe/Amsterdam`, landing in Live state. Live state still renders SVG + `data-qr-refresh-endpoint-value`, which is what the existing assertions check.)

If the existing `testDisplayPageRendersQrEncodingPhotosUrlInCurrentFormat` fails because it does not park the clock, update it to call `$clock->modify('2026-06-12 12:00:00');` and assert `data-qr-refresh-state-value="live"`. That's the natural place to add the live-state assertion required by the AC.

- [ ] **Step 3.7: Stage and let the user commit**

```bash
git add src/Controller/Public/EventController.php templates/public/event/display.html.twig tests/Functional/Public/EventDisplayTest.php
```

Suggested commit message:
```
46 - render display page with pre-event branch and visible photos URL
```

---

## Task 4: Live-state boundary tests + Post branch on `display()`

**Files:**
- Modify: `tests/Functional/Public/EventDisplayTest.php`

The controller already supports Post via the `match` branch added in Task 3 (returns `null` photosUrl/qrSvg). The template already renders the "ended" message. So this task is mostly tests, with a small possible adjustment if the live tests need clock parking.

- [ ] **Step 4.1: Update the existing live-state test to be explicit about state and clock**

Replace the body of `testDisplayPageRendersQrEncodingPhotosUrlInCurrentFormat` in `tests/Functional/Public/EventDisplayTest.php` with (rename to `testDisplayPageInLiveStateRendersTimestampedQr`):

```php
public function testDisplayPageInLiveStateRendersTimestampedQr(): void
{
    $client    = self::createClient();
    $container = self::getContainer();
    /** @var EntityManagerInterface $em */
    $em = $container->get(EntityManagerInterface::class);
    /** @var MockClock $clock */
    $clock = $container->get(ClockInterface::class);

    $owner = new User('display-owner@example.test', 'O');
    $owner->setPassword('x');
    $em->persist($owner);

    $event = new Event(
        'big-night',
        'Big Night',
        new DateTimeImmutable('2026-06-12 10:00', new \DateTimeZone('Europe/Amsterdam')),
        new DateTimeImmutable('2026-06-12 14:00', new \DateTimeZone('Europe/Amsterdam')),
        $owner,
    );
    $event->setTimezone('Europe/Amsterdam');
    $em->persist($event);
    $em->flush();

    // Park clock at 12:00 Europe/Amsterdam (10:00 UTC) — comfortably inside the window.
    $clock->modify('2026-06-12 12:00:00 Europe/Amsterdam');

    $client->request(Request::METHOD_GET, '/e/big-night/display');

    $this->assertResponseIsSuccessful();
    $this->assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');

    $html = (string) $client->getResponse()->getContent();

    $this->assertStringContainsString('Big Night', $html);
    $this->assertStringContainsString('<svg', $html);
    $this->assertStringContainsString('data-qr-refresh-state-value="live"', $html);
    $this->assertStringContainsString(
        'data-qr-refresh-endpoint-value="/e/big-night/display/qr.svg"',
        $html,
    );
    // Live link encodes t=12:00.
    $this->assertMatchesRegularExpression(
        '#href="https?://[^"]+/e/big-night/photos\?t=12%3A00"#',
        $html,
    );
}
```

- [ ] **Step 4.2: Add boundary tests for `startsAt` and `endsAt`**

Append two tests:

```php
public function testDisplayPageAtStartsAtBoundaryIsLive(): void
{
    $client    = self::createClient();
    $container = self::getContainer();
    /** @var EntityManagerInterface $em */
    $em = $container->get(EntityManagerInterface::class);
    /** @var MockClock $clock */
    $clock = $container->get(ClockInterface::class);

    $owner = new User('boundary-start@example.test', 'O');
    $owner->setPassword('x');
    $em->persist($owner);

    $event = new Event(
        'start-edge',
        'Start Edge',
        new DateTimeImmutable('2026-06-12 10:00', new \DateTimeZone('Europe/Amsterdam')),
        new DateTimeImmutable('2026-06-12 14:00', new \DateTimeZone('Europe/Amsterdam')),
        $owner,
    );
    $event->setTimezone('Europe/Amsterdam');
    $em->persist($event);
    $em->flush();

    $clock->modify('2026-06-12 10:00:00 Europe/Amsterdam');

    $client->request(Request::METHOD_GET, '/e/start-edge/display');

    $this->assertResponseIsSuccessful();
    $html = (string) $client->getResponse()->getContent();
    $this->assertStringContainsString('data-qr-refresh-state-value="live"', $html);
}

public function testDisplayPageAtEndsAtBoundaryIsLive(): void
{
    $client    = self::createClient();
    $container = self::getContainer();
    /** @var EntityManagerInterface $em */
    $em = $container->get(EntityManagerInterface::class);
    /** @var MockClock $clock */
    $clock = $container->get(ClockInterface::class);

    $owner = new User('boundary-end@example.test', 'O');
    $owner->setPassword('x');
    $em->persist($owner);

    $event = new Event(
        'end-edge',
        'End Edge',
        new DateTimeImmutable('2026-06-12 10:00', new \DateTimeZone('Europe/Amsterdam')),
        new DateTimeImmutable('2026-06-12 14:00', new \DateTimeZone('Europe/Amsterdam')),
        $owner,
    );
    $event->setTimezone('Europe/Amsterdam');
    $em->persist($event);
    $em->flush();

    $clock->modify('2026-06-12 14:00:00 Europe/Amsterdam');

    $client->request(Request::METHOD_GET, '/e/end-edge/display');

    $this->assertResponseIsSuccessful();
    $html = (string) $client->getResponse()->getContent();
    $this->assertStringContainsString('data-qr-refresh-state-value="live"', $html);
}
```

- [ ] **Step 4.3: Add the Post-state test**

Append:

```php
public function testDisplayPageInPostEventStateHasNoQrAndShowsEndedMessage(): void
{
    $client    = self::createClient();
    $container = self::getContainer();
    /** @var EntityManagerInterface $em */
    $em = $container->get(EntityManagerInterface::class);
    /** @var MockClock $clock */
    $clock = $container->get(ClockInterface::class);

    $owner = new User('post-owner@example.test', 'O');
    $owner->setPassword('x');
    $em->persist($owner);

    $event = new Event(
        'past-night',
        'Past Night',
        new DateTimeImmutable('2026-06-12 10:00', new \DateTimeZone('Europe/Amsterdam')),
        new DateTimeImmutable('2026-06-12 14:00', new \DateTimeZone('Europe/Amsterdam')),
        $owner,
    );
    $event->setTimezone('Europe/Amsterdam');
    $em->persist($event);
    $em->flush();

    $clock->modify('2026-06-12 15:00:00 Europe/Amsterdam');

    $client->request(Request::METHOD_GET, '/e/past-night/display');

    $this->assertResponseIsSuccessful();
    $html = (string) $client->getResponse()->getContent();

    $this->assertStringContainsString('Past Night', $html);
    $this->assertStringContainsString('This event has ended.', $html);
    // No QR, no Stimulus controller wiring.
    $this->assertStringNotContainsString('<svg', $html);
    $this->assertStringNotContainsString('data-controller="qr-refresh"', $html);
    $this->assertStringNotContainsString('data-qr-refresh-state-value', $html);
}
```

- [ ] **Step 4.4: Run the full display test file**

```bash
vendor/bin/phpunit tests/Functional/Public/EventDisplayTest.php
```

Expected: all tests in the file pass (existing + new). The Post branch on `display()` should already render correctly because Task 3 implemented the `match` arm and the template's `state.value == 'post'` branch.

If a controller-side bug shows up (e.g., `qrSvg` being passed as `null` triggers a Twig warning despite the `state.value != 'post'` guard), check whether the template guard correctly short-circuits — Twig should not evaluate `{{ qrSvg|raw }}` when its surrounding `{% if %}` is false.

- [ ] **Step 4.5: Stage and let the user commit**

```bash
git add tests/Functional/Public/EventDisplayTest.php
```

Suggested commit message:
```
46 - cover live/boundary/post states for display page
```

---

## Task 5: State-aware refresh endpoint (`displayQr()`)

**Files:**
- Modify: `src/Controller/Public/EventController.php`
- Modify: `tests/Functional/Public/EventDisplayTest.php`

- [ ] **Step 5.1: Write the failing tests for all three refresh states**

Append to `tests/Functional/Public/EventDisplayTest.php`:

```php
public function testRefreshEndpointInPreStateReturnsSvgAndStateHeaders(): void
{
    $client    = self::createClient();
    $container = self::getContainer();
    /** @var EntityManagerInterface $em */
    $em = $container->get(EntityManagerInterface::class);
    /** @var MockClock $clock */
    $clock = $container->get(ClockInterface::class);

    $owner = new User('refresh-pre@example.test', 'O');
    $owner->setPassword('x');
    $em->persist($owner);

    $event = new Event(
        'refresh-pre',
        'Refresh Pre',
        new DateTimeImmutable('2026-07-15 19:00', new \DateTimeZone('Europe/Amsterdam')),
        new DateTimeImmutable('2026-07-15 23:00', new \DateTimeZone('Europe/Amsterdam')),
        $owner,
    );
    $event->setTimezone('Europe/Amsterdam');
    $em->persist($event);
    $em->flush();

    $clock->modify('2026-07-15 16:00:00');

    $client->request(Request::METHOD_GET, '/e/refresh-pre/display/qr.svg');

    $this->assertResponseIsSuccessful();
    $this->assertResponseHeaderSame('Content-Type', 'image/svg+xml');
    $this->assertResponseHeaderSame('X-Display-State', 'pre');
    $photosUrl = $client->getResponse()->headers->get('X-Photos-Url') ?? '';
    $this->assertMatchesRegularExpression(
        '#^https?://[^/]+/e/refresh-pre/photos\?t=19%3A00$#',
        $photosUrl,
    );
    $this->assertStringContainsString('<svg', (string) $client->getResponse()->getContent());
}

public function testRefreshEndpointInLiveStateReturnsSvgAndLiveHeader(): void
{
    $client    = self::createClient();
    $container = self::getContainer();
    /** @var EntityManagerInterface $em */
    $em = $container->get(EntityManagerInterface::class);
    /** @var MockClock $clock */
    $clock = $container->get(ClockInterface::class);

    $owner = new User('refresh-live@example.test', 'O');
    $owner->setPassword('x');
    $em->persist($owner);

    $event = new Event(
        'refresh-live',
        'Refresh Live',
        new DateTimeImmutable('2026-06-12 10:00', new \DateTimeZone('Europe/Amsterdam')),
        new DateTimeImmutable('2026-06-12 14:00', new \DateTimeZone('Europe/Amsterdam')),
        $owner,
    );
    $event->setTimezone('Europe/Amsterdam');
    $em->persist($event);
    $em->flush();

    $clock->modify('2026-06-12 12:00:00 Europe/Amsterdam');

    $client->request(Request::METHOD_GET, '/e/refresh-live/display/qr.svg');

    $this->assertResponseIsSuccessful();
    $this->assertResponseHeaderSame('Content-Type', 'image/svg+xml');
    $this->assertResponseHeaderSame('X-Display-State', 'live');
    $photosUrl = $client->getResponse()->headers->get('X-Photos-Url') ?? '';
    $this->assertMatchesRegularExpression(
        '#^https?://[^/]+/e/refresh-live/photos\?t=12%3A00$#',
        $photosUrl,
    );
}

public function testRefreshEndpointInPostStateReturns204WithStateHeader(): void
{
    $client    = self::createClient();
    $container = self::getContainer();
    /** @var EntityManagerInterface $em */
    $em = $container->get(EntityManagerInterface::class);
    /** @var MockClock $clock */
    $clock = $container->get(ClockInterface::class);

    $owner = new User('refresh-post@example.test', 'O');
    $owner->setPassword('x');
    $em->persist($owner);

    $event = new Event(
        'refresh-post',
        'Refresh Post',
        new DateTimeImmutable('2026-06-12 10:00', new \DateTimeZone('Europe/Amsterdam')),
        new DateTimeImmutable('2026-06-12 14:00', new \DateTimeZone('Europe/Amsterdam')),
        $owner,
    );
    $event->setTimezone('Europe/Amsterdam');
    $em->persist($event);
    $em->flush();

    $clock->modify('2026-06-12 15:00:00 Europe/Amsterdam');

    $client->request(Request::METHOD_GET, '/e/refresh-post/display/qr.svg');

    $this->assertResponseStatusCodeSame(204);
    $this->assertResponseHeaderSame('X-Display-State', 'post');
    $this->assertEmpty((string) $client->getResponse()->getContent());
    $this->assertFalse($client->getResponse()->headers->has('X-Photos-Url'));
}
```

Also update the existing `testRefreshEndpointReturnsSvgWithFreshT` to assert `X-Display-State: live` and rename it `testRefreshEndpointLiveCarriesNoStoreCacheControl`, OR delete it as redundant with the new live test above. Pick whichever keeps the suite minimal — recommend deletion to avoid duplication.

- [ ] **Step 5.2: Run the new tests to verify they fail**

```bash
vendor/bin/phpunit --filter testRefreshEndpointIn tests/Functional/Public/EventDisplayTest.php
```

Expected: failures (the current `displayQr` always returns Live SVG with no state header).

- [ ] **Step 5.3: Modify `displayQr()`**

Replace the body of `displayQr()` in `src/Controller/Public/EventController.php` (current lines 115–134) with:

```php
public function displayQr(string $slug): Response
{
    $event         = $this->resolve($slug);
    [$now, $state] = $this->resolveNowAndState($event);
    $photosUrl     = $this->buildPhotosUrlForState($event, $now, $state);

    if ($state === EventDisplayState::Post) {
        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('X-Display-State', $state->value);

        return $response;
    }

    $svg = $this->qr->svg(
        $photosUrl,
        $this->readLogoBytes($event),
        size: self::DISPLAY_QR_SIZE,
    );

    $response = new Response($svg);
    $response->headers->set('Content-Type', 'image/svg+xml');
    $response->headers->set('Cache-Control', 'no-store');
    $response->headers->set('X-Display-State', $state->value);
    $response->headers->set('X-Photos-Url', $photosUrl);

    return $response;
}
```

Notes:
- `Response::HTTP_NO_CONTENT` is the named constant — `phpmnd` will complain about a literal `204`, the constant satisfies it.
- The `Cache-Control` `no-store` is preserved for the SVG paths; `Symfony` will auto-append `, private` (existing comment in the file documents this). We do **not** set Cache-Control on the 204.
- `$photosUrl` is non-null in Pre and Live branches by construction (the `match` in `buildPhotosUrlForState` returns null only for Post).

PHPStan note: if it complains that `$photosUrl` may be null when passed to `qr->svg()` and `headers->set('X-Photos-Url', $photosUrl)`, add an `assert(...)` or refactor `buildPhotosUrlForState` to return `string` for Pre/Live and split the Post path out. Cleanest fix:

```php
$photosUrl = match ($state) {
    EventDisplayState::Pre  => $this->photosUrl->build($event, $event->getStartsAt()->setTimezone(new DateTimeZone($event->getTimezone())), absolute: true),
    EventDisplayState::Live => $this->photosUrl->build($event, $now, absolute: true),
};
```

…inside the non-Post path, so the variable is never null there. Adjust the helper accordingly, OR keep the nullable helper and add `assert($photosUrl !== null);` after the Post early-return. Either is acceptable; choose the variant that keeps phpstan green.

- [ ] **Step 5.4: Run all `EventDisplayTest` tests**

```bash
vendor/bin/phpunit tests/Functional/Public/EventDisplayTest.php
```

Expected: all green.

- [ ] **Step 5.5: Stage and let the user commit**

```bash
git add src/Controller/Public/EventController.php tests/Functional/Public/EventDisplayTest.php
```

Suggested commit message:
```
46 - return state-aware refresh response with X-Display-State and X-Photos-Url
```

---

## Task 6: Client-side reload-on-state-change + URL swap

**Files:**
- Modify: `assets/controllers/qr_refresh_controller.js`

No automated tests for this — the project does not have a JS test setup. Verification is manual (Step 6.3).

- [ ] **Step 6.1: Add the new value, target, and behaviour**

Replace the entire contents of `assets/controllers/qr_refresh_controller.js` with:

```javascript
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['qr', 'updated', 'photosUrl'];

    static values = {
        endpoint: String,
        timezone: String,
        state: String,
        intervalMs: { type: Number, default: 60000 },
    };

    connect() {
        // Defensive: Post state should never wire this controller, but if it does, do nothing.
        if (this.stateValue === 'post') {
            return;
        }
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

            const serverState = response.headers.get('X-Display-State');

            // State transition detected (pre <-> live, or anything -> post).
            // Reload so the server can render the new layout.
            if (serverState && serverState !== this.stateValue) {
                window.location.reload();
                return;
            }

            if (!response.ok) {
                return;
            }

            const svg = await response.text();
            if (this.hasQrTarget) {
                this.qrTarget.innerHTML = svg;
            }

            const photosUrl = response.headers.get('X-Photos-Url');
            if (photosUrl && this.hasPhotosUrlTarget) {
                this.photosUrlTarget.href = photosUrl;
                this.photosUrlTarget.textContent = photosUrl;
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

- [ ] **Step 6.2: Run the whole test suite once to ensure no regression (PHP-side)**

```bash
vendor/bin/phpunit
```

Expected: all green.

- [ ] **Step 6.3: Manual smoke test (browser)**

```bash
docker compose up -d
docker compose logs -f worker  # optional
```

Open three browser tabs:

1. **Pre case** — pick an event with `startsAt` in the future. Visit `/e/<slug>/display`. Confirm:
   - The page shows "Starts HH:mm".
   - There is a visible URL link beneath the QR with `?t=<startsAt-HH:mm>`.
   - `view-source:` shows `data-qr-refresh-state-value="pre"`.
   - In DevTools → Network, the qr.svg poll happens every 60 s and returns `X-Display-State: pre`. The page does not reload.

2. **Live case** — pick an event whose window includes now (or temporarily adjust startsAt/endsAt). Visit `/e/<slug>/display`. Confirm:
   - The "Updated HH:mm" timestamp ticks every minute.
   - The visible URL changes every minute as `t` advances.
   - Each qr.svg response carries `X-Display-State: live` and `X-Photos-Url: <abs url>`.

3. **Transition case (optional but high-value)** — using an event whose startsAt is ~90 seconds in the future, load `/display` (renders Pre), wait for startsAt to pass plus one tick. Confirm the page does a full reload on its own and switches to Live layout.

4. **Post case** — pick an event whose `endsAt` is in the past. Visit `/e/<slug>/display`. Confirm:
   - "This event has ended." is shown.
   - There is no `<svg>` in the DOM.
   - There is no qr.svg polling in Network.

- [ ] **Step 6.4: Stage and let the user commit**

```bash
git add assets/controllers/qr_refresh_controller.js
```

Suggested commit message:
```
46 - reload display on state transition; swap photos URL on refresh
```

---

## Task 7: Run the full GrumPHP gate

**Files:** none

- [ ] **Step 7.1: Run the full gate**

```bash
vendor/bin/grumphp run
```

Expected: green across all tasks (phpstan, phpcs, phpmnd, phpcpd, rector, securitychecker_roave, doctrine:schema:validate, phpunit).

Likely friction points and fixes:

- **phpmnd** — any numeric literal in `src/`. The plan uses `self::DISPLAY_QR_SIZE`, `Response::HTTP_NO_CONTENT`, and constants from `Event`; no new literals introduced. If a literal slips in, promote to a `private const int` on the class.
- **phpstan level 10** — most likely complaint is on a nullable `$photosUrl` being passed to `qr->svg()` or `headers->set()`. Resolve as described in Task 5 Step 5.3.
- **rector** — accept its auto-fixes if any (run `vendor/bin/rector process`).
- **phpcs PSR-12** — auto-fix with `vendor/bin/phpcbf` if needed.
- **doctrine:schema:validate** — should be unaffected since no entity columns changed.

- [ ] **Step 7.2: If grumphp finds issues, fix them and re-run; otherwise stage any auto-fixes**

```bash
# If anything was auto-fixed:
git add -p
```

Suggested commit message (only if there are fixes to commit):
```
46 - apply grumphp auto-fixes
```

---

## Task 8: Final verification

- [ ] **Step 8.1: Confirm acceptance criteria against the spec**

Re-read `docs/superpowers/specs/2026-06-12-46-display-event-window-gating-design.md` "Acceptance criteria" section and tick each item off mentally against the implemented tests and manual smoke results.

- [ ] **Step 8.2: Push and open PR**

```bash
git push -u origin feature/46-display-window-gating
gh pr create --title "46 - gate display QR by event window (pre/live/post)" --body "$(cat <<'EOF'
## Summary
- Adds `EventDisplayState` enum and `Event::computeDisplayState()`; classifies `(now, startsAt, endsAt)` as Pre / Live / Post with `[startsAt, endsAt]` inclusive.
- `/e/{slug}/display` renders three branches:
  - **Pre** — static QR anchored to `startsAt`; visible photos URL beneath.
  - **Live** — existing behaviour preserved; visible photos URL that updates on each refresh.
  - **Post** — "this event has ended" message; no QR, no polling.
- `/e/{slug}/display/qr.svg` carries `X-Display-State` on every response (and `X-Photos-Url` in Pre/Live). The Stimulus controller reloads the page on state mismatch, so an unattended venue screen self-heals across `startsAt`/`endsAt` transitions.
- Wires `MockClock` as `ClockInterface` in the test env to enable deterministic boundary assertions.

Closes #46.

## Test plan
- [ ] `vendor/bin/phpunit tests/Unit/Entity/EventTest.php` — passes
- [ ] `vendor/bin/phpunit tests/Functional/Public/EventDisplayTest.php` — passes
- [ ] `vendor/bin/grumphp run` — passes
- [ ] Manual: visit a pre-event, live, and post-event display URL in a browser; confirm layout and polling behaviour per spec
EOF
)"
```

---

## Self-review notes

- **Spec coverage**: every AC from the spec has at least one task — enum + method (Task 1), MockClock infra (Task 2), Pre rendering (Task 3), Live + boundaries + Post (Task 4), refresh endpoint (Task 5), client transition (Task 6), CI gate (Task 7).
- **No placeholders**: all code blocks are concrete; commands are runnable.
- **Type consistency**: `EventDisplayState` and `computeDisplayState` used consistently across all tasks. `resolveNowAndState` / `buildPhotosUrlForState` referenced only after they are introduced in Task 3.
- **TDD shape**: each behaviour-bearing task writes the failing test first, sees it fail, implements, sees it pass, then stages. JS task (6) is the only exception — no JS test infra exists; manual verification compensates.
