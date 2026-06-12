# Mandatory Event Window Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `Event::$startsAt` / `$endsAt` mandatory (constructor-required, NOT NULL, with validation), remove the redundant `Event::$date` column, rewire the admin form, public timestamp resolver, three templates, and drop the test-data hack in `Photo::markReady`.

**Architecture:** The window pair `(startsAt, endsAt)` becomes the single source of truth for an event's calendar position. `date` is dropped after a tz-aware backfill. The admin form composes the two UTC timestamps from a single `eventDate` + two `HH:mm` inputs (with midnight rollover). The public timestamp resolver matches `HH:mm` against `startsAt`'s calendar date in event tz, falling back to `endsAt`'s date for midnight-crossing events. Validation rejects `endsAt ≤ startsAt` and windows wider than 1440 minutes (single-day events only).

**Tech Stack:** PHP 8.5, Symfony 8 Form / Validator / Routing, Doctrine ORM 3 + Migrations (Postgres 16), Twig 3, PHPUnit 13, Vich Uploader (unchanged), `dama/doctrine-test-bundle` for transactional integration tests.

**Issue:** [#45](https://github.com/jwderoos/eventPhotos/issues/45)

**Branch:** `feature/45-mandatory-event-window` (matches GrumPHP `^(feature|hotfix|bugfix|release)/\d+-` gate)

**Commit message style:** Each commit must contain the issue number (GrumPHP gate). Use the form: `45 - <one-line summary>` (mirrors recent project history, e.g., `37 - adds a dynamic display QR mode...`).

---

## File Structure

**Modified:**
- `src/Entity/Event.php` — constructor now requires `(slug, name, startsAt, endsAt, owner)`; `$date` property/column/accessors removed; `#[Assert\Callback] assertValidWindow()` added.
- `src/Entity/Photo.php` — remove lines 138-142 (the `@todo` test-data scaffolding).
- `src/Form/EventType.php` — replace `date` / `startsAt` / `endsAt` widgets with one `eventDate` (DateType) + two `HH:mm` inputs (TextType); add `PRE_SET_DATA` + `SUBMIT` listeners that translate between unmapped form fields and the entity's UTC timestamps.
- `src/Controller/Admin/EventController.php` — `new()` passes startsAt/endsAt instead of `today`; `index()` sort key `date` → `startsAt`.
- `src/Controller/Admin/DashboardController.php` — sort key `date` → `startsAt`.
- `src/Controller/Public/EventController.php` — `resolveTimestamp()` rewritten to anchor on `startsAt`'s calendar date (with fallback to `endsAt`'s date when they differ).
- `templates/admin/event/index.html.twig` — replace `event.date|date('Y-m-d')` with the new Twig filter.
- `templates/admin/event/qr.html.twig` — same.
- `templates/admin/dashboard.html.twig` — same.

**Created:**
- `src/Twig/EventDateExtension.php` — Twig extension exposing `event_date_in_tz(Event)` filter that returns `event.startsAt` formatted as `Y-m-d` in `event.timezone`.
- `migrations/Version<TS>.php` — generated via `doctrine:migrations:diff`, then hand-edited to insert backfill SQL between the column-add-defaults and `NOT NULL`/`DROP` steps.

**Modified test fixtures (mechanical — ~30 call sites):** every `new Event($slug, $name, new DateTimeImmutable('YYYY-MM-DD'), $owner)` becomes `new Event($slug, $name, new DateTimeImmutable('YYYY-MM-DD 10:00'), new DateTimeImmutable('YYYY-MM-DD 14:00'), $owner)` unless a test specifically needs other times.

**New tests:**
- `tests/Unit/Entity/EventTest.php` — add boundary cases for the validator (equal, off-by-one, 1440 exact, 1441) using Symfony's `ValidatorBuilder`.
- `tests/Unit/Form/EventTypeWindowCompositionTest.php` — unit-test the form's composition of `eventDate` + two `HH:mm` strings into UTC `startsAt`/`endsAt` (including midnight rollover, Europe/Amsterdam DST-active and `Pacific/Honolulu` no-DST).
- `tests/Functional/Admin/EventWindowFormTest.php` — admin create/edit accepts the new fields, rejects `endsAt - startsAt > 1440`.
- `tests/Functional/Public/MidnightCrossingTest.php` — `Public\EventController` resolves `HH:mm` correctly on both sides of midnight; rejects out-of-window with 400.

---

## Pre-flight

- [ ] **Step 0a: Create the feature branch via worktree**

The repo uses GrumPHP to gate commits on branch name. Set up an isolated worktree using `superpowers:using-git-worktrees`. If skipping the skill, run from repo root:

```bash
git fetch origin
git worktree add ../eventFotos--feature-45-mandatory-event-window -b feature/45-mandatory-event-window origin/main
cd ../eventFotos--feature-45-mandatory-event-window
composer install --no-interaction
```

- [ ] **Step 0b: Sanity check — current suite is green**

Run: `vendor/bin/phpunit`
Expected: PASS (the change must start from a green baseline so every regression is attributable).

---

## Task 1: Event entity — validator (boundary tests first)

**Files:**
- Modify: `src/Entity/Event.php`
- Modify: `tests/Unit/Entity/EventTest.php`

The validator only depends on `startsAt`/`endsAt` and can be added BEFORE the constructor change. We add tests, then the callback, while the properties are still nullable. The constructor change comes in Task 2.

- [ ] **Step 1a: Append failing validator boundary tests to `tests/Unit/Entity/EventTest.php`**

Add at the end of the class:

```php
    public function testValidatorRejectsEndsAtEqualToStartsAt(): void
    {
        $event = new Event('e', 'E', new DateTimeImmutable('2026-07-15'), new User('o@x', 'Owner'));
        $event->setStartsAt(new DateTimeImmutable('2026-07-15 10:00', new DateTimeZone('Europe/Amsterdam')));
        $event->setEndsAt(new DateTimeImmutable('2026-07-15 10:00', new DateTimeZone('Europe/Amsterdam')));

        $violations = $this->validator()->validate($event);

        $this->assertNotCount(0, $violations, 'endsAt == startsAt must be rejected');
    }

    public function testValidatorRejectsEndsAtBeforeStartsAt(): void
    {
        $event = new Event('e', 'E', new DateTimeImmutable('2026-07-15'), new User('o@x', 'Owner'));
        $event->setStartsAt(new DateTimeImmutable('2026-07-15 12:00', new DateTimeZone('Europe/Amsterdam')));
        $event->setEndsAt(new DateTimeImmutable('2026-07-15 11:59', new DateTimeZone('Europe/Amsterdam')));

        $violations = $this->validator()->validate($event);

        $this->assertNotCount(0, $violations);
    }

    public function testValidatorAcceptsExactlyOneMinuteWindow(): void
    {
        $event = new Event('e', 'E', new DateTimeImmutable('2026-07-15'), new User('o@x', 'Owner'));
        $event->setStartsAt(new DateTimeImmutable('2026-07-15 10:00', new DateTimeZone('Europe/Amsterdam')));
        $event->setEndsAt(new DateTimeImmutable('2026-07-15 10:01', new DateTimeZone('Europe/Amsterdam')));

        $violations = $this->validator()->validate($event);

        $this->assertCount(0, $violations);
    }

    public function testValidatorAcceptsExactly1440MinuteWindow(): void
    {
        $event = new Event('e', 'E', new DateTimeImmutable('2026-07-15'), new User('o@x', 'Owner'));
        $event->setStartsAt(new DateTimeImmutable('2026-07-15 10:00', new DateTimeZone('Europe/Amsterdam')));
        $event->setEndsAt(new DateTimeImmutable('2026-07-16 10:00', new DateTimeZone('Europe/Amsterdam')));

        $violations = $this->validator()->validate($event);

        $this->assertCount(0, $violations);
    }

    public function testValidatorRejects1441MinuteWindow(): void
    {
        $event = new Event('e', 'E', new DateTimeImmutable('2026-07-15'), new User('o@x', 'Owner'));
        $event->setStartsAt(new DateTimeImmutable('2026-07-15 10:00', new DateTimeZone('Europe/Amsterdam')));
        $event->setEndsAt(new DateTimeImmutable('2026-07-16 10:01', new DateTimeZone('Europe/Amsterdam')));

        $violations = $this->validator()->validate($event);

        $this->assertNotCount(0, $violations);
    }

    private function validator(): \Symfony\Component\Validator\Validator\ValidatorInterface
    {
        return \Symfony\Component\Validator\Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }
```

Also add the required imports at the top of the test file:

```php
use DateTimeZone;
```

- [ ] **Step 1b: Run new tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Entity/EventTest.php`
Expected: 5 new failures — "endsAt == startsAt must be rejected" etc. (No validation exists yet.)

- [ ] **Step 1c: Add the `#[Assert\Callback]` to `src/Entity/Event.php`**

Add the import (already imported):
```php
use Symfony\Component\Validator\Context\ExecutionContextInterface;
```

Insert this method into the `Event` class (after `resolveWindowMinutes()` is a sensible spot):

```php
    #[Assert\Callback]
    public function assertValidWindow(ExecutionContextInterface $context): void
    {
        if (!$this->startsAt instanceof DateTimeImmutable || !$this->endsAt instanceof DateTimeImmutable) {
            return;
        }

        if ($this->endsAt <= $this->startsAt) {
            $context->buildViolation('End must be strictly after start.')
                ->atPath('endsAt')
                ->addViolation();

            return;
        }

        $diffMinutes = (int) floor(
            ($this->endsAt->getTimestamp() - $this->startsAt->getTimestamp()) / 60
        );
        if ($diffMinutes > self::MAX_WINDOW_MINUTES) {
            $context->buildViolation('Event window cannot exceed 24 hours.')
                ->atPath('endsAt')
                ->addViolation();
        }
    }
```

And add the constant near `DEFAULT_WINDOW_MINUTES`:

```php
    public const int MAX_WINDOW_MINUTES = 1440;
```

- [ ] **Step 1d: Run new tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Entity/EventTest.php`
Expected: ALL PASS (existing + 5 new boundary tests).

- [ ] **Step 1e: Commit**

```bash
git add src/Entity/Event.php tests/Unit/Entity/EventTest.php
git commit -m "45 - add validator for event window bounds (>start, ≤24h)"
```

---

## Task 2: Event constructor — startsAt + endsAt required; drop `date`

This is a coordinated, multi-file change because the constructor signature is shared by ~30 test call sites and 1 production call site. Update the entity AND every caller in one commit so the suite never crosses an intermediate broken state.

**Files:**
- Modify: `src/Entity/Event.php`
- Modify: `src/Entity/Photo.php`
- Modify: `src/Controller/Admin/EventController.php`
- Modify: `src/Controller/Admin/DashboardController.php`
- Modify: `tests/Unit/Entity/EventTest.php`
- Modify: `tests/Unit/Entity/PhotoTest.php`
- Modify: `tests/Unit/Security/EventVoterTest.php`
- Modify: `tests/Unit/Security/PhotoVoterTest.php`
- Modify: `tests/Unit/Service/Event/PhotosUrlBuilderTest.php`
- Modify: `tests/Unit/EventListener/EventSlugListenerTest.php`
- Modify: `tests/Integration/Repository/CountByOwnerTest.php`
- Modify: `tests/Integration/Repository/PhotoRepositoryPaginationTest.php`
- Modify: `tests/Integration/Repository/PhotoRepositoryTest.php`
- Modify: `tests/Integration/MessageHandler/ProcessPhotoHandlerTest.php`
- Modify: `tests/Functional/Admin/EventLogoUploadTest.php`
- Modify: `tests/Functional/Admin/PhotoPaginationTest.php`
- Modify: `tests/Functional/Admin/EventSlugTest.php`
- Modify: `tests/Functional/Admin/UserCrudTest.php`
- Modify: `tests/Functional/Admin/OwnershipScopingTest.php`
- Modify: `tests/Functional/Admin/EventQrTest.php`
- Modify: `tests/Functional/Admin/PhotoManagePageTest.php`
- Modify: `tests/Functional/Admin/PhotoUploadTest.php`
- Modify: `tests/Functional/Admin/PhotoModerationTest.php`
- Modify: `tests/Functional/Public/EventPhotosGalleryTest.php`
- Modify: `tests/Functional/Public/EventDisplayTest.php`
- Modify: `tests/Functional/Public/PhotoServeTest.php`
- Modify: `tests/Functional/Public/EventPhotosStubTest.php`
- Modify: `tests/Functional/Public/EventLandingTest.php`

- [ ] **Step 2a: Rewrite the `Event` constructor**

In `src/Entity/Event.php`:

Replace the existing nullable column attributes on lines 32-36 with required ones (drop the property declarations there; they will move into the constructor):

```php
    // (delete the standalone declarations on lines 29-39 for $startsAt and $endsAt;
    // they move into the constructor signature below)
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $defaultWindowMinutes = null;
```

Replace the constructor (currently lines 63-74) with:

```php
    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 120)]
        private string $slug,
        #[ORM\Column(type: Types::STRING, length: 200)]
        private string $name,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $startsAt,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $endsAt,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false)]
        private User $owner,
    ) {
    }
```

Delete the `$date` property, its column attribute, `getDate()`, and `setDate()` entirely (lines 68-69 in original constructor and lines 111-119 in the original getter/setter pair).

Change the `getStartsAt` / `getEndsAt` signatures to non-nullable, and remove the nullable parameter on their setters:

```php
    public function getStartsAt(): DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(DateTimeImmutable $startsAt): void
    {
        $this->startsAt = $startsAt;
    }

    public function getEndsAt(): DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(DateTimeImmutable $endsAt): void
    {
        $this->endsAt = $endsAt;
    }
```

The `assertValidWindow` method's nullable guard can now be simplified — both properties are guaranteed:

```php
    #[Assert\Callback]
    public function assertValidWindow(ExecutionContextInterface $context): void
    {
        if ($this->endsAt <= $this->startsAt) {
            $context->buildViolation('End must be strictly after start.')
                ->atPath('endsAt')
                ->addViolation();

            return;
        }

        $diffMinutes = (int) floor(
            ($this->endsAt->getTimestamp() - $this->startsAt->getTimestamp()) / 60
        );
        if ($diffMinutes > self::MAX_WINDOW_MINUTES) {
            $context->buildViolation('Event window cannot exceed 24 hours.')
                ->atPath('endsAt')
                ->addViolation();
        }
    }
```

- [ ] **Step 2b: Drop the Photo::markReady test-data hack**

In `src/Entity/Photo.php`, delete lines 138-142 (the `//hack for test data @todo : remove` block). The `markReady` body should now go straight from the status check to:

```php
        $this->takenAt = $takenAt;

        $this->width = $width;
        $this->height = $height;
        $this->processingError = null;
        $this->status = PhotoStatus::Ready;
```

- [ ] **Step 2c: Update Admin EventController + DashboardController for the new constructor and sort key**

In `src/Controller/Admin/EventController.php`:

Replace the `new Event(...)` call at line 62:

```php
        $now      = new DateTimeImmutable('today 10:00');
        $startsAt = $now;
        $endsAt   = $now->modify('+2 hours');
        $event    = new Event('', '', $startsAt, $endsAt, $user);
```

Change the sort key at line 49:

```php
            'events' => $this->events->findBy($criteria, ['startsAt' => 'DESC']),
```

In `src/Controller/Admin/DashboardController.php` line 36, change the sort key the same way:

```php
        $eventList = $this->events->findBy($eventCriteria, ['startsAt' => 'DESC'], 25);
```

- [ ] **Step 2d: Update all test fixtures to the new constructor**

For every test file listed under "Files" above, replace the pattern:

```php
new Event($slug, $name, new DateTimeImmutable('YYYY-MM-DD'), $owner)
```

with:

```php
new Event($slug, $name, new DateTimeImmutable('YYYY-MM-DD 10:00'), new DateTimeImmutable('YYYY-MM-DD 14:00'), $owner)
```

Concrete rule for the date: **preserve the original date** in BOTH the start and end (same day, 10:00 → 14:00). Do this per-file with the Edit tool; do NOT use a global sed — some sites use multi-line `new Event(` blocks (e.g., `EventSlugTest.php:62`, `EventDisplayTest.php:28`, `EventSlugListenerTest.php:29`).

Special cases:
- `tests/Unit/Entity/EventTest.php` lines 18-19: this is `testNewEventExposesRequiredFields`. The `$date` variable and `assertSame($date, $event->getDate())` assertion (line 23) must be removed. Replace with:
  ```php
      public function testNewEventExposesRequiredFields(): void
      {
          $owner    = new User('owner@example.com', 'Owner');
          $startsAt = new DateTimeImmutable('2026-07-15 10:00');
          $endsAt   = new DateTimeImmutable('2026-07-15 14:00');
          $event    = new Event('summer-fest', 'Summer Fest', $startsAt, $endsAt, $owner);

          $this->assertSame('summer-fest', $event->getSlug());
          $this->assertSame('Summer Fest', $event->getName());
          $this->assertSame($startsAt, $event->getStartsAt());
          $this->assertSame($endsAt, $event->getEndsAt());
          $this->assertSame($owner, $event->getOwner());
          $this->assertNotInstanceOf(EventCollection::class, $event->getCollection());
          $this->assertNull($event->getDefaultWindowMinutes());
      }
  ```
- `tests/Functional/Admin/EventSlugTest.php` line 41: the form submission uses `'event[date]' => '2026-08-01'`. This field no longer exists; we will rework it in Task 5. For now, replace the form payload with:
  ```php
          'event[name]'      => 'My Brand New Event',
          'event[eventDate]' => '2026-08-01',
          'event[startTime]' => '10:00',
          'event[endTime]'   => '14:00',
  ```

- [ ] **Step 2e: Run the unit + integration test layers to verify the bulk of the suite goes green**

Run: `vendor/bin/phpunit --testsuite Unit --testsuite Integration` (or `vendor/bin/phpunit tests/Unit tests/Integration` if no testsuites are configured)
Expected: PASS. Functional tests that touch the admin form are expected to fail until Task 5 — that's fine and tracked.

- [ ] **Step 2f: Commit**

```bash
git add src/Entity/Event.php src/Entity/Photo.php \
        src/Controller/Admin/EventController.php src/Controller/Admin/DashboardController.php \
        tests/Unit tests/Integration tests/Functional
git commit -m "45 - replace Event::\$date with required startsAt/endsAt; drop markReady test-data hack"
```

---

## Task 3: Twig `event_date_in_tz` filter

**Files:**
- Create: `src/Twig/EventDateExtension.php`
- Create: `tests/Unit/Twig/EventDateExtensionTest.php`

- [ ] **Step 3a: Write the failing test**

Create `tests/Unit/Twig/EventDateExtensionTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Entity\Event;
use App\Entity\User;
use App\Twig\EventDateExtension;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class EventDateExtensionTest extends TestCase
{
    public function testReturnsStartsAtDateInEventTimezone(): void
    {
        // 2026-07-15 22:00 UTC = 2026-07-16 00:00 Europe/Amsterdam (DST, UTC+2)
        $startsAt = new DateTimeImmutable('2026-07-15 22:00', new DateTimeZone('UTC'));
        $endsAt   = new DateTimeImmutable('2026-07-16 02:00', new DateTimeZone('UTC'));
        $event    = new Event('e', 'E', $startsAt, $endsAt, new User('o@x', 'O'));
        $event->setTimezone('Europe/Amsterdam');

        $this->assertSame('2026-07-16', (new EventDateExtension())->eventDateInTz($event));
    }

    public function testReturnsCalendarDateForUtcTimezone(): void
    {
        $event = new Event(
            'e', 'E',
            new DateTimeImmutable('2026-01-15 10:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-01-15 14:00', new DateTimeZone('UTC')),
            new User('o@x', 'O'),
        );
        $event->setTimezone('UTC');

        $this->assertSame('2026-01-15', (new EventDateExtension())->eventDateInTz($event));
    }
}
```

- [ ] **Step 3b: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Twig/EventDateExtensionTest.php`
Expected: FAIL with "Class App\Twig\EventDateExtension not found".

- [ ] **Step 3c: Create the extension**

Create `src/Twig/EventDateExtension.php`:

```php
<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Event;
use DateTimeZone;
use Override;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class EventDateExtension extends AbstractExtension
{
    /**
     * @return list<TwigFilter>
     */
    #[Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('event_date_in_tz', $this->eventDateInTz(...)),
        ];
    }

    public function eventDateInTz(Event $event): string
    {
        return $event->getStartsAt()
            ->setTimezone(new DateTimeZone($event->getTimezone()))
            ->format('Y-m-d');
    }
}
```

- [ ] **Step 3d: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Twig/EventDateExtensionTest.php`
Expected: PASS.

- [ ] **Step 3e: Update the three templates**

In `templates/admin/event/index.html.twig` line 38:

```twig
<td>{{ event|event_date_in_tz }}</td>
```

In `templates/admin/event/qr.html.twig` line 21:

```twig
<p class="text-base-content/70 mb-2">{{ event|event_date_in_tz }}</p>
```

In `templates/admin/dashboard.html.twig` line 35:

```twig
— {{ event|event_date_in_tz }} (slug: <code class="text-xs">{{ event.slug }}</code>)
```

- [ ] **Step 3f: Commit**

```bash
git add src/Twig/EventDateExtension.php tests/Unit/Twig/EventDateExtensionTest.php \
        templates/admin/event/index.html.twig templates/admin/event/qr.html.twig \
        templates/admin/dashboard.html.twig
git commit -m "45 - add event_date_in_tz Twig filter; update event-date displays"
```

---

## Task 4: Public controller — `resolveTimestamp` handles midnight crossing

**Files:**
- Modify: `src/Controller/Public/EventController.php`
- Create: `tests/Functional/Public/MidnightCrossingTest.php`

- [ ] **Step 4a: Write the failing functional test**

Create `tests/Functional/Public/MidnightCrossingTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class MidnightCrossingTest extends WebTestCase
{
    private function seedMidnightEvent(): void
    {
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $owner = new User('mc-owner@example.test', 'O');
        $owner->setPassword('x');
        $em->persist($owner);

        // 2026-06-12 22:00 Europe/Amsterdam (UTC+2 in summer) → 2026-06-13 02:00 Europe/Amsterdam
        $tz       = new DateTimeZone('Europe/Amsterdam');
        $startsAt = new DateTimeImmutable('2026-06-12 22:00', $tz);
        $endsAt   = new DateTimeImmutable('2026-06-13 02:00', $tz);

        $event = new Event('midnight', 'Midnight', $startsAt, $endsAt, $owner);
        $event->setTimezone('Europe/Amsterdam');

        $em->persist($event);
        $em->flush();
    }

    public function testResolvesTimeOnStartsAtDate(): void
    {
        $client = self::createClient();
        $this->seedMidnightEvent();

        $client->request(Request::METHOD_GET, '/e/midnight/photos?t=23:30');
        $this->assertResponseIsSuccessful();
    }

    public function testResolvesTimeOnEndsAtDateAfterMidnight(): void
    {
        $client = self::createClient();
        $this->seedMidnightEvent();

        $client->request(Request::METHOD_GET, '/e/midnight/photos?t=01:30');
        $this->assertResponseIsSuccessful();
    }

    public function testRejectsTimeOutsideBothDates(): void
    {
        $client = self::createClient();
        $this->seedMidnightEvent();

        // 10:00 maps to neither 22:00..23:59 on day 1 nor 00:00..02:00 on day 2.
        $client->request(Request::METHOD_GET, '/e/midnight/photos?t=10:00');
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }
}
```

- [ ] **Step 4b: Run to verify failure**

Run: `vendor/bin/phpunit tests/Functional/Public/MidnightCrossingTest.php`
Expected: at least `testResolvesTimeOnEndsAtDateAfterMidnight` and `testRejectsTimeOutsideBothDates` FAIL — the current implementation only anchors on `getDate()` (which no longer compiles, anyway).

- [ ] **Step 4c: Rewrite `resolveTimestamp`**

In `src/Controller/Public/EventController.php`, replace the entire `resolveTimestamp` method (lines 152-174):

```php
    private function resolveTimestamp(mixed $raw, Event $event): DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return $this->nowInEventTimezone($event);
        }

        if (preg_match(self::TIME_PATTERN, $raw) !== 1) {
            throw new BadRequestHttpException('Invalid time. Expected HH:mm.');
        }

        $tz       = new DateTimeZone($event->getTimezone());
        $startsAt = $event->getStartsAt();
        $endsAt   = $event->getEndsAt();
        $startDay = $startsAt->setTimezone($tz)->format('Y-m-d');
        $endDay   = $endsAt->setTimezone($tz)->format('Y-m-d');

        $candidate = $this->composeOnDay($startDay, $raw, $tz);
        if ($candidate >= $startsAt && $candidate <= $endsAt) {
            return $candidate;
        }

        if ($endDay !== $startDay) {
            $candidate = $this->composeOnDay($endDay, $raw, $tz);
            if ($candidate >= $startsAt && $candidate <= $endsAt) {
                return $candidate;
            }
        }

        throw new BadRequestHttpException('Time is outside the event window.');
    }

    private function composeOnDay(string $day, string $time, DateTimeZone $tz): DateTimeImmutable
    {
        $resolved = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i',
            sprintf('%s %s', $day, $time),
            $tz,
        );

        if (!$resolved instanceof DateTimeImmutable) {
            throw new BadRequestHttpException('Invalid time. Expected HH:mm.');
        }

        return $resolved;
    }
```

- [ ] **Step 4d: Run to verify tests pass**

Run: `vendor/bin/phpunit tests/Functional/Public/MidnightCrossingTest.php`
Expected: PASS.

Also re-run the existing public tests so we know we didn't regress same-day events:

Run: `vendor/bin/phpunit tests/Functional/Public`
Expected: PASS.

- [ ] **Step 4e: Commit**

```bash
git add src/Controller/Public/EventController.php tests/Functional/Public/MidnightCrossingTest.php
git commit -m "45 - resolve public HH:mm against start/end calendar dates (midnight-crossing)"
```

---

## Task 5: Admin form — eventDate + startTime + endTime, with composition

The form is the most subtle piece. We use three unmapped fields on the form, and form event listeners to translate between them and the entity's UTC datetimes.

**Files:**
- Modify: `src/Form/EventType.php`
- Create: `tests/Unit/Form/EventTypeWindowCompositionTest.php`
- Create: `tests/Functional/Admin/EventWindowFormTest.php`
- Modify: `tests/Functional/Admin/EventSlugTest.php` (already pre-edited in Task 2 — verify still consistent)

- [ ] **Step 5a: Write the failing composition unit test**

Create `tests/Unit/Form/EventTypeWindowCompositionTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Entity\Event;
use App\Entity\User;
use App\Form\EventType;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Form\PreloadedExtension;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Verifies the form's composition of (eventDate, startTime, endTime) into UTC
 * startsAt/endsAt on the entity, including midnight rollover and tz handling.
 *
 * We test EventType in isolation; full HTTP submission is covered functionally
 * in EventWindowFormTest.
 */
final class EventTypeWindowCompositionTest extends TypeTestCase
{
    public function testComposesUtcStartAndEndForSameDayEventAmsterdam(): void
    {
        $event = $this->newEvent('Europe/Amsterdam');

        $this->submitDate($event, '2026-07-15', '10:00', '14:00');

        $this->assertSame(
            '2026-07-15 08:00:00',
            $event->getStartsAt()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'Amsterdam DST (UTC+2) means 10:00 local = 08:00 UTC',
        );
        $this->assertSame(
            '2026-07-15 12:00:00',
            $event->getEndsAt()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        );
    }

    public function testRollsEndsAtToNextDayWhenEndTimeIsLessThanOrEqualToStartTime(): void
    {
        $event = $this->newEvent('Europe/Amsterdam');

        // 22:00 → 02:00 should yield a window of exactly 4 hours that crosses midnight.
        $this->submitDate($event, '2026-07-15', '22:00', '02:00');

        $this->assertSame(
            '2026-07-15 22:00',
            $event->getStartsAt()->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('Y-m-d H:i'),
        );
        $this->assertSame(
            '2026-07-16 02:00',
            $event->getEndsAt()->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('Y-m-d H:i'),
        );
    }

    public function testRollsEndsAtToNextDayWhenEndTimeEqualsStartTime(): void
    {
        $event = $this->newEvent('Europe/Amsterdam');

        $this->submitDate($event, '2026-07-15', '10:00', '10:00');

        // Equal → rolls to next day → 24h window (still allowed by ≤1440 rule).
        $this->assertSame(
            '2026-07-16 10:00',
            $event->getEndsAt()->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('Y-m-d H:i'),
        );
    }

    public function testNonDstTimezoneComposesCorrectly(): void
    {
        $event = $this->newEvent('Pacific/Honolulu');

        $this->submitDate($event, '2026-07-15', '10:00', '14:00');

        // Hawaii is UTC-10 year-round.
        $this->assertSame(
            '2026-07-15 20:00:00',
            $event->getStartsAt()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        );
    }

    private function submitDate(Event $event, string $date, string $start, string $end): void
    {
        $form = $this->factory->create(EventType::class, $event);
        $form->submit([
            'name'       => $event->getName(),
            'eventDate'  => $date,
            'startTime'  => $start,
            'endTime'    => $end,
            'timezone'   => $event->getTimezone(),
        ]);
        // We only care about the composed datetimes; if the form is "not valid"
        // it can still have populated the entity via the SUBMIT listener.
    }

    private function newEvent(string $tz): Event
    {
        $owner = new User('o@x', 'O');
        $event = new Event('e', 'E',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );
        $event->setTimezone($tz);

        return $event;
    }

    /** @return iterable<PreloadedExtension> */
    protected function getExtensions(): iterable
    {
        /** @var Security&MockObject $security */
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);
        $security->method('isGranted')->willReturn(false);

        return [new PreloadedExtension([new EventType($security)], [])];
    }
}
```

- [ ] **Step 5b: Run to verify failure**

Run: `vendor/bin/phpunit tests/Unit/Form/EventTypeWindowCompositionTest.php`
Expected: FAIL — the form still uses the old `date`/`startsAt`/`endsAt` fields.

- [ ] **Step 5c: Rewrite `src/Form/EventType.php`**

Replace the entire file with:

```php
<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Event;
use App\Entity\EventCollection;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Form\Type\VichFileType;

/**
 * @extends AbstractType<Event>
 */
final class EventType extends AbstractType
{
    private const string TIME_PATTERN = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

    public function __construct(private readonly Security $security)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $timezones = DateTimeZone::listIdentifiers();

        $builder
            ->add('name', TextType::class)
            ->add('description', TextareaType::class, ['required' => false])
            ->add('eventDate', DateType::class, [
                'mapped'   => false,
                'widget'   => 'single_text',
                'required' => true,
                'label'    => 'Date',
            ])
            ->add('startTime', TextType::class, [
                'mapped'      => false,
                'required'    => true,
                'label'       => 'Start (HH:mm)',
                'attr'        => ['placeholder' => 'HH:mm', 'pattern' => '[0-2][0-9]:[0-5][0-9]'],
                'constraints' => [new Assert\Regex(self::TIME_PATTERN, 'Expected HH:mm.')],
            ])
            ->add('endTime', TextType::class, [
                'mapped'      => false,
                'required'    => true,
                'label'       => 'End (HH:mm) — rolls to next day if ≤ start',
                'attr'        => ['placeholder' => 'HH:mm', 'pattern' => '[0-2][0-9]:[0-5][0-9]'],
                'constraints' => [new Assert\Regex(self::TIME_PATTERN, 'Expected HH:mm.')],
            ])
            ->add('defaultWindowMinutes', IntegerType::class, [
                'required' => false,
                'help'     => sprintf('Minutes around "now". Empty → default %d.', Event::DEFAULT_WINDOW_MINUTES),
            ])
            ->add('timezone', ChoiceType::class, [
                'choices' => array_combine($timezones, $timezones),
                'help'    => 'IANA zone for EXIF timestamps without an explicit offset.',
            ]);

        $builder->add('logoFile', VichFileType::class, [
            'required'     => false,
            'label'        => 'Logo (PNG or JPEG, max 2 MB)',
            'allow_delete' => true,
            'download_uri' => false,
        ]);

        $user    = $this->security->getUser();
        $isAdmin = $this->security->isGranted('ROLE_ADMIN');

        $builder->add('collection', EntityType::class, [
            'class'         => EventCollection::class,
            'choice_label'  => 'name',
            'required'      => false,
            'placeholder'   => '— none —',
            'query_builder' => static function (EntityRepository $repo) use ($user, $isAdmin): QueryBuilder {
                $qb = $repo->createQueryBuilder('c')->orderBy('c.name', 'ASC');

                if (!$isAdmin && $user instanceof User) {
                    $qb->andWhere('c.owner = :owner')->setParameter('owner', $user);
                }

                return $qb;
            },
        ]);

        if ($isAdmin) {
            $builder->add('owner', EntityType::class, [
                'class'        => User::class,
                'choice_label' => 'email',
            ]);
        }

        $builder->addEventListener(FormEvents::PRE_SET_DATA, $this->prefillUnmappedFields(...));
        $builder->addEventListener(FormEvents::SUBMIT, $this->composeStartsAndEnds(...));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Event::class]);
    }

    private function prefillUnmappedFields(FormEvent $formEvent): void
    {
        $event = $formEvent->getData();
        if (!$event instanceof Event) {
            return;
        }

        $tz       = new DateTimeZone($event->getTimezone());
        $startsAt = $event->getStartsAt()->setTimezone($tz);
        $endsAt   = $event->getEndsAt()->setTimezone($tz);

        $form = $formEvent->getForm();
        $form->get('eventDate')->setData(new DateTimeImmutable($startsAt->format('Y-m-d')));
        $form->get('startTime')->setData($startsAt->format('H:i'));
        $form->get('endTime')->setData($endsAt->format('H:i'));
    }

    private function composeStartsAndEnds(FormEvent $formEvent): void
    {
        $event = $formEvent->getData();
        if (!$event instanceof Event) {
            return;
        }

        $form      = $formEvent->getForm();
        $date      = $form->get('eventDate')->getData();
        $startTime = $form->get('startTime')->getData();
        $endTime   = $form->get('endTime')->getData();

        if (!$date instanceof DateTimeImmutable
            || !is_string($startTime) || preg_match(self::TIME_PATTERN, $startTime) !== 1
            || !is_string($endTime) || preg_match(self::TIME_PATTERN, $endTime) !== 1
        ) {
            return; // Field-level constraints will surface the error; don't mutate the entity.
        }

        $tz       = new DateTimeZone($event->getTimezone());
        $day      = $date->format('Y-m-d');
        $startsAt = DateTimeImmutable::createFromFormat('Y-m-d H:i', sprintf('%s %s', $day, $startTime), $tz);
        $endsAt   = DateTimeImmutable::createFromFormat('Y-m-d H:i', sprintf('%s %s', $day, $endTime), $tz);

        if (!$startsAt instanceof DateTimeImmutable || !$endsAt instanceof DateTimeImmutable) {
            return;
        }

        if ($endsAt <= $startsAt) {
            $endsAt = $endsAt->modify('+1 day');
        }

        $event->setStartsAt($startsAt);
        $event->setEndsAt($endsAt);
    }
}
```

- [ ] **Step 5d: Run unit tests to verify the form composes correctly**

Run: `vendor/bin/phpunit tests/Unit/Form/EventTypeWindowCompositionTest.php`
Expected: PASS.

- [ ] **Step 5e: Write the failing functional test for admin create/edit**

Create `tests/Functional/Admin/EventWindowFormTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Repository\EventRepository;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EventWindowFormTest extends WebTestCase
{
    public function testCreateEventComposesUtcStartAndEnd(): void
    {
        $client    = self::createClient();
        $container = self::getContainer();
        $alice     = $this->seedOrganizer();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, '/admin/events/new');
        $form    = $crawler->selectButton('Create')->form([
            'event[name]'      => 'Window Event',
            'event[eventDate]' => '2026-07-15',
            'event[startTime]' => '10:00',
            'event[endTime]'   => '14:00',
            'event[timezone]'  => 'Europe/Amsterdam',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/admin/events');

        /** @var EventRepository $events */
        $events  = $container->get(EventRepository::class);
        $created = $events->findOneBy(['name' => 'Window Event']);
        self::assertNotNull($created);

        self::assertSame(
            '2026-07-15 08:00:00',
            $created->getStartsAt()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        );
        self::assertSame(
            '2026-07-15 12:00:00',
            $created->getEndsAt()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        );
    }

    public function testCreateEventRejects25HourWindow(): void
    {
        $client = self::createClient();
        $alice  = $this->seedOrganizer();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, '/admin/events/new');
        $form    = $crawler->selectButton('Create')->form([
            'event[name]'      => 'Too Long',
            'event[eventDate]' => '2026-07-15',
            'event[startTime]' => '10:00',
            'event[endTime]'   => '11:00', // rolls → next day 11:00 → 25h window
            'event[timezone]'  => 'Europe/Amsterdam',
        ]);
        $client->submit($form);

        // Form re-renders with errors (200 OK), not the 302 redirect of a successful create.
        self::assertResponseIsSuccessful();
    }

    private function seedOrganizer(): User
    {
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $alice = new User('alice@example.com', 'Alice');
        $alice->addRole('ROLE_ORGANIZER');
        $alice->setPassword($hasher->hashPassword($alice, 'pw'));

        $em->persist($alice);
        $em->flush();

        return $alice;
    }
}
```

- [ ] **Step 5f: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Admin/EventWindowFormTest.php tests/Functional/Admin/EventSlugTest.php`
Expected: PASS.

- [ ] **Step 5g: Run the full functional admin layer to check for regressions in other form-driven tests**

Run: `vendor/bin/phpunit tests/Functional/Admin`
Expected: PASS.

- [ ] **Step 5h: Commit**

```bash
git add src/Form/EventType.php tests/Unit/Form tests/Functional/Admin/EventWindowFormTest.php
git commit -m "45 - rewrite admin EventType: date + two HH:mm with midnight rollover"
```

---

## Task 6: Migration — backfill + NOT NULL + drop `date`

**Files:**
- Create: `migrations/Version<TS>.php` (timestamp filled in by `doctrine:migrations:diff`)

- [ ] **Step 6a: Generate the migration diff**

From the host (NOT inside docker), with the local Postgres up:

```bash
bin/console doctrine:migrations:diff
```

Expected: a new `migrations/Version<TS>.php`. It should contain SQL approximately like:
- `ALTER TABLE events ALTER starts_at SET NOT NULL`
- `ALTER TABLE events ALTER ends_at SET NOT NULL`
- `ALTER TABLE events DROP date`

These statements need backfill SQL inserted **before** the `SET NOT NULL` and `DROP` steps. (If the diff also re-orders columns or adds/removes indexes unrelated to this change, do NOT edit those — only insert the backfill.)

- [ ] **Step 6b: Hand-insert the backfill SQL**

Open the generated `migrations/Version<TS>.php` and replace `getDescription()` + `up()` with:

```php
    public function getDescription(): string
    {
        return 'Backfill events.starts_at/ends_at from date in event timezone; make NOT NULL; drop date column.';
    }

    public function up(Schema $schema): void
    {
        // 1. Backfill any rows where starts_at IS NULL using midnight (event tz) of the date column.
        //    `date::timestamp AT TIME ZONE timezone` interprets midnight-of-day as a wall-clock time in the
        //    event's IANA zone, producing a `timestamptz`. The outer `AT TIME ZONE 'UTC'` collapses it back
        //    to a `timestamp` (no tz) showing the UTC instant — which is what Doctrine's
        //    DATETIME_IMMUTABLE column expects.
        $this->addSql(<<<'SQL'
            UPDATE events
            SET starts_at = (date::timestamp AT TIME ZONE timezone) AT TIME ZONE 'UTC'
            WHERE starts_at IS NULL
        SQL);

        // 2. Backfill ends_at to 23:59 of the date column in event tz.
        $this->addSql(<<<'SQL'
            UPDATE events
            SET ends_at = ((date + INTERVAL '1 day' - INTERVAL '1 minute')::timestamp AT TIME ZONE timezone) AT TIME ZONE 'UTC'
            WHERE ends_at IS NULL
        SQL);

        // 3. The original Doctrine-generated DDL — paste below this comment EXACTLY as `doctrine:migrations:diff`
        //    produced it. Do not hand-write column/index names.
        // <PASTE GENERATED $this->addSql(...) STATEMENTS HERE>
    }
```

Replace the placeholder line with whatever statements the diff originally produced. Then write `down()` (also generated by the diff — keep as-is or adjust).

- [ ] **Step 6c: Run the migration locally and verify schema is in sync**

```bash
bin/console doctrine:migrations:migrate --no-interaction
bin/console doctrine:schema:validate
```

Expected: `[Mapping] OK` and `[Database] OK`.

- [ ] **Step 6d: Run the test DB migrations**

```bash
bin/console doctrine:database:drop --env=test --force --if-exists
bin/console doctrine:database:create --env=test
bin/console doctrine:migrations:migrate --no-interaction --env=test
bin/console doctrine:schema:validate --env=test
```

Expected: clean.

- [ ] **Step 6e: Full test suite**

Run: `vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 6f: Commit**

```bash
git add migrations/
git commit -m "45 - migration: backfill starts_at/ends_at in event tz, NOT NULL, drop date"
```

---

## Task 7: GrumPHP — full quality gate

- [ ] **Step 7a: Run GrumPHP**

Run: `vendor/bin/grumphp run`
Expected: ALL tasks pass — phpstan (level 10), phpcs (PSR-12), phpmnd, phpcpd, rector, securitychecker_roave, doctrine:schema:validate, phpunit.

- [ ] **Step 7b: Fix anything that fails**

Common likely failures:
- **PHPMND**: the new validator constants (`MAX_WINDOW_MINUTES = 1440`) and form regex are designed to avoid magic numbers; if PHPMND still flags something inside the form's listener (`'+1 day'`, etc.), reference a class constant.
- **PHPSTAN**: setStartsAt/getStartsAt non-nullable changes may surface assumptions elsewhere. Fix at the call site, not by re-introducing nullability.
- **Rector**: may want to convert `is_string` checks into a typed-array helper; only accept Rector's suggestion if it does not weaken the regex guard.

After each fix, re-run `vendor/bin/grumphp run`.

- [ ] **Step 7c: Commit any quality fixes**

```bash
git add <fixed files>
git commit -m "45 - quality gate fixes"
```

---

## Task 8: Verification before completion

Use the `superpowers:verification-before-completion` discipline.

- [ ] **Step 8a: Re-run the full suite from a clean state**

```bash
vendor/bin/phpunit
vendor/bin/grumphp run
bin/console doctrine:schema:validate
```

Expected: all green.

- [ ] **Step 8b: Manual smoke (admin)**

```bash
docker compose up -d
```

In the browser at `http://localhost:8080/admin/events/new`:
1. Create an event with date `2026-07-15`, start `22:00`, end `02:00`. Save.
2. Verify the event lists with the date `2026-07-15`.
3. Visit `/admin/events/{id}/edit` — verify the form prefills as `2026-07-15`, `22:00`, `02:00`.
4. Save again with no changes — should round-trip cleanly.

- [ ] **Step 8c: Manual smoke (public, midnight crossing)**

For the event above:
- `GET /e/<slug>/photos?t=23:30` → 200
- `GET /e/<slug>/photos?t=01:30` → 200
- `GET /e/<slug>/photos?t=10:00` → 400

- [ ] **Step 8d: Confirm acceptance criteria one-by-one**

Mark each item in the issue's acceptance criteria checklist:
- [ ] `doctrine:schema:validate` clean after migration ✅
- [ ] Cannot create or update an event with `endsAt ≤ startsAt` ✅
- [ ] Cannot create or update an event with `endsAt - startsAt > 1440` minutes ✅
- [ ] All existing prod rows have non-null start/end after migration ✅ (verified by migration design — `NOT NULL` would fail if any row didn't backfill)
- [ ] Admin form accepts date + two HH:mm and stores correct UTC datetimes ✅
- [ ] Guest `HH:mm` input resolves correctly for events that cross midnight ✅
- [ ] `Photo::markReady` no longer references `event.getDate()` ✅
- [ ] PHPStan level 10, PHPCS, Rector, GrumPHP all green ✅

---

## Self-review checklist (already applied)

- **Spec coverage**: every section of the issue maps to a task (domain → Task 1+2; admin form → Task 5; public controller → Task 4; Photo hack → Task 2b; migration → Task 6; templates → Task 3).
- **Placeholder scan**: there is one bona-fide placeholder in Task 6b (`<PASTE GENERATED $this->addSql(...) STATEMENTS HERE>`) — this is unavoidable because the migration must be generated by `doctrine:migrations:diff` per project rule; the surrounding backfill SQL is fully specified.
- **Type consistency**: `Event::getStartsAt(): DateTimeImmutable` (non-nullable) is used uniformly in Task 3 (Twig), Task 4 (controller), Task 5 (form prefill), Task 6 (migration leaves nothing nullable). `MAX_WINDOW_MINUTES = 1440` is referenced only inside the entity. `event_date_in_tz` filter name matches between extension and templates.
