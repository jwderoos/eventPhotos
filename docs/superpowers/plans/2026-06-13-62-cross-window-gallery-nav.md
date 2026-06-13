# Cross-Window Gallery Navigation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `[« First] [‹ Previous] [Next ›] [Last »]` navigation above the photo grid on `/e/{slug}/photos`, jumping the `?t=` cursor between Ready photos along the event timeline.

**Architecture:** Four new `PhotoRepository` cursor methods, each returning `?DateTimeImmutable`. `Public\EventController::photos` calls them after resolving the current timestamp and passes the four `?DateTimeImmutable` values to the template. The Twig template renders an enabled `<a href>` or a disabled `<span>` per button based on whether the value is `null`.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3 + PostgreSQL 16, Twig, Tailwind/DaisyUI, PHPUnit 13 with `dama/doctrine-test-bundle`.

**Issue:** [#62](https://github.com/jwderoos/eventPhotos/issues/62)

**Out of scope (per issue):** within-window nav (#60), `?t=` day disambiguation (#59 interplay), wraparound, keyboard shortcuts, window math changes.

---

## Pre-flight

- [ ] **Step 0.1: Confirm branch**

Branch must already exist and match `^(feature|hotfix|bugfix|release)/\d+-` (GrumPHP gate). Branch name for this work: `feature/62-cross-window-gallery-nav`.

Run:
```bash
git checkout -b feature/62-cross-window-gallery-nav
```

Expected: `Switched to a new branch 'feature/62-cross-window-gallery-nav'`. If it already exists, `git checkout feature/62-cross-window-gallery-nav`.

- [ ] **Step 0.2: Verify clean baseline**

Run: `vendor/bin/phpunit --testsuite=Integration tests/Integration/Repository/PhotoRepositoryTest.php && vendor/bin/phpunit tests/Functional/Public/EventPhotosGalleryTest.php`

Expected: both files green. (Baseline guard — confirms we're not starting from a broken tree.)

---

## File Structure

- **Modify** `src/Repository/PhotoRepository.php` — add four cursor finder methods (`findFirstReadyTakenAt`, `findLastReadyTakenAt`, `findPreviousReadyTakenAt`, `findNextReadyTakenAt`).
- **Modify** `src/Controller/Public/EventController.php` — compute four cursors in `photos()` after `$timestamp`, pass to template.
- **Modify** `templates/public/event/photos.html.twig` — render the four-button nav row above the grid.
- **Modify** `tests/Integration/Repository/PhotoRepositoryTest.php` — append cursor-method tests.
- **Modify** `tests/Functional/Public/EventPhotosGalleryTest.php` — append nav-button rendering tests.

No new files. Everything piggybacks on existing classes / templates / test files.

---

## Task 1: Repository — `findFirstReadyTakenAt` / `findLastReadyTakenAt`

**Files:**
- Test: `tests/Integration/Repository/PhotoRepositoryTest.php`
- Modify: `src/Repository/PhotoRepository.php`

- [ ] **Step 1.1: Add the failing tests**

Append these methods to the existing `PhotoRepositoryTest` class (the file's setup already creates `$this->event` and helpers `createReady` / `createPending`):

```php
public function testFindFirstReadyTakenAtReturnsNullWhenNoReadyPhotos(): void
{
    $this->createPending();
    $this->em->flush();

    self::assertNull($this->repo->findFirstReadyTakenAt($this->event));
}

public function testFindLastReadyTakenAtReturnsNullWhenNoReadyPhotos(): void
{
    $this->createPending();
    $this->em->flush();

    self::assertNull($this->repo->findLastReadyTakenAt($this->event));
}

public function testFindFirstReadyTakenAtReturnsEarliestReady(): void
{
    $this->createReady('2026-06-10 12:30:00');
    $this->createReady('2026-06-10 11:00:00');
    $this->createReady('2026-06-10 13:45:00');
    $this->em->flush();

    $first = $this->repo->findFirstReadyTakenAt($this->event);

    self::assertNotNull($first);
    self::assertSame('2026-06-10 11:00:00', $first->format('Y-m-d H:i:s'));
}

public function testFindLastReadyTakenAtReturnsLatestReady(): void
{
    $this->createReady('2026-06-10 12:30:00');
    $this->createReady('2026-06-10 11:00:00');
    $this->createReady('2026-06-10 13:45:00');
    $this->em->flush();

    $last = $this->repo->findLastReadyTakenAt($this->event);

    self::assertNotNull($last);
    self::assertSame('2026-06-10 13:45:00', $last->format('Y-m-d H:i:s'));
}

public function testFindFirstLastIgnorePendingAndFailedPhotos(): void
{
    $this->createPending();                          // takenAt is null
    $ready = $this->createReady('2026-06-10 12:00:00');
    $failed = $this->createReady('2026-06-10 09:00:00');
    $failed->markFailed('forced');
    $this->em->flush();

    $first = $this->repo->findFirstReadyTakenAt($this->event);
    $last  = $this->repo->findLastReadyTakenAt($this->event);

    self::assertNotNull($first);
    self::assertNotNull($last);
    self::assertSame($ready->getTakenAt()?->format('Y-m-d H:i:s'), $first->format('Y-m-d H:i:s'));
    self::assertSame($ready->getTakenAt()?->format('Y-m-d H:i:s'), $last->format('Y-m-d H:i:s'));
}
```

**Note on the failed-photo path:** if `Photo::markFailed` doesn't accept a reason argument, drop the argument — check the entity. The point is *any* non-Ready status next to a Ready row.

- [ ] **Step 1.2: Run the tests, confirm failure**

Run: `vendor/bin/phpunit tests/Integration/Repository/PhotoRepositoryTest.php --filter 'FindFirst|FindLast|FindFirstLast'`

Expected: 5 failing tests with `Error: Call to undefined method App\Repository\PhotoRepository::findFirstReadyTakenAt()` (and `findLastReadyTakenAt`).

- [ ] **Step 1.3: Implement both methods**

Add to `src/Repository/PhotoRepository.php` (after `findReadyInWindow`):

```php
public function findFirstReadyTakenAt(Event $event): ?DateTimeImmutable
{
    return $this->findReadyTakenAtOrdered($event, 'ASC');
}

public function findLastReadyTakenAt(Event $event): ?DateTimeImmutable
{
    return $this->findReadyTakenAtOrdered($event, 'DESC');
}

private function findReadyTakenAtOrdered(Event $event, string $direction): ?DateTimeImmutable
{
    /** @var DateTimeImmutable|null $result */
    $result = $this->createQueryBuilder('p')
        ->select('p.takenAt')
        ->andWhere('p.event = :event')
        ->andWhere('p.status = :status')
        ->setParameter('event', $event)
        ->setParameter('status', PhotoStatus::Ready)
        ->orderBy('p.takenAt', $direction)
        ->setMaxResults(1)
        ->getQuery()
        ->getOneOrNullResult(\Doctrine\ORM\AbstractQuery::HYDRATE_SINGLE_SCALAR);

    return $result;
}
```

**Why single-scalar hydration:** matches the spec's "minimal projection — `takenAt`, nothing else." Avoids hydrating a full `Photo` entity for what's just one column.

If PHPStan complains about the `HYDRATE_SINGLE_SCALAR` constant return type, swap to scalar result and re-wrap:

```php
$value = $this->createQueryBuilder('p')
    ->select('p.takenAt')
    ->andWhere('p.event = :event')
    ->andWhere('p.status = :status')
    ->setParameter('event', $event)
    ->setParameter('status', PhotoStatus::Ready)
    ->orderBy('p.takenAt', $direction)
    ->setMaxResults(1)
    ->getQuery()
    ->getOneOrNullResult();

if ($value === null) {
    return null;
}

$takenAt = is_array($value) ? ($value['takenAt'] ?? null) : $value;

return $takenAt instanceof DateTimeImmutable ? $takenAt : null;
```

Pick the form that survives `vendor/bin/phpstan analyse`. Commit message says "feat: …" regardless.

- [ ] **Step 1.4: Run the tests, confirm green**

Run: `vendor/bin/phpunit tests/Integration/Repository/PhotoRepositoryTest.php --filter 'FindFirst|FindLast|FindFirstLast'`

Expected: 5 tests pass.

- [ ] **Step 1.5: PHPStan + commit**

Run: `vendor/bin/phpstan analyse src/Repository/PhotoRepository.php`

Expected: no errors.

```bash
git add src/Repository/PhotoRepository.php tests/Integration/Repository/PhotoRepositoryTest.php
git commit -m "62 - add first/last Ready takenAt cursors on PhotoRepository"
```

(Do not push — user commits & pushes themselves; this commit is fine to leave staged-then-committed locally.)

---

## Task 2: Repository — `findPreviousReadyTakenAt` / `findNextReadyTakenAt`

**Files:**
- Test: `tests/Integration/Repository/PhotoRepositoryTest.php`
- Modify: `src/Repository/PhotoRepository.php`

- [ ] **Step 2.1: Add the failing tests**

Append to `PhotoRepositoryTest`:

```php
public function testFindPreviousReadyTakenAtReturnsNullWhenCursorAtOrBeforeEarliest(): void
{
    $this->createReady('2026-06-10 12:00:00');
    $this->createReady('2026-06-10 13:00:00');
    $this->em->flush();

    $tz = new DateTimeZone('UTC');

    self::assertNull($this->repo->findPreviousReadyTakenAt(
        $this->event,
        new DateTimeImmutable('2026-06-10 12:00:00', $tz),
    ));
    self::assertNull($this->repo->findPreviousReadyTakenAt(
        $this->event,
        new DateTimeImmutable('2026-06-10 11:00:00', $tz),
    ));
}

public function testFindPreviousReadyTakenAtReturnsStrictlyEarlierPhoto(): void
{
    $this->createReady('2026-06-10 11:00:00');
    $this->createReady('2026-06-10 12:00:00');
    $this->createReady('2026-06-10 13:00:00');
    $this->em->flush();

    $previous = $this->repo->findPreviousReadyTakenAt(
        $this->event,
        new DateTimeImmutable('2026-06-10 12:30:00', new DateTimeZone('UTC')),
    );

    self::assertNotNull($previous);
    self::assertSame('2026-06-10 12:00:00', $previous->format('Y-m-d H:i:s'));
}

public function testFindNextReadyTakenAtReturnsNullWhenCursorAtOrAfterLatest(): void
{
    $this->createReady('2026-06-10 12:00:00');
    $this->createReady('2026-06-10 13:00:00');
    $this->em->flush();

    $tz = new DateTimeZone('UTC');

    self::assertNull($this->repo->findNextReadyTakenAt(
        $this->event,
        new DateTimeImmutable('2026-06-10 13:00:00', $tz),
    ));
    self::assertNull($this->repo->findNextReadyTakenAt(
        $this->event,
        new DateTimeImmutable('2026-06-10 14:30:00', $tz),
    ));
}

public function testFindNextReadyTakenAtReturnsStrictlyLaterPhoto(): void
{
    $this->createReady('2026-06-10 11:00:00');
    $this->createReady('2026-06-10 12:00:00');
    $this->createReady('2026-06-10 13:00:00');
    $this->em->flush();

    $next = $this->repo->findNextReadyTakenAt(
        $this->event,
        new DateTimeImmutable('2026-06-10 11:30:00', new DateTimeZone('UTC')),
    );

    self::assertNotNull($next);
    self::assertSame('2026-06-10 12:00:00', $next->format('Y-m-d H:i:s'));
}

public function testFindPreviousNextSkipPendingAndFailed(): void
{
    $this->createReady('2026-06-10 11:00:00');
    $pendingBetween = $this->createPending();             // no takenAt anyway, but explicit
    self::assertSame('Pending', $pendingBetween->getStatus()->name);
    $this->createReady('2026-06-10 13:00:00');

    // A failed Ready-ish photo sandwiched in
    $failed = $this->createReady('2026-06-10 12:00:00');
    $failed->markFailed('forced');

    $this->em->flush();

    $tz = new DateTimeZone('UTC');

    $next = $this->repo->findNextReadyTakenAt(
        $this->event,
        new DateTimeImmutable('2026-06-10 11:30:00', $tz),
    );
    $previous = $this->repo->findPreviousReadyTakenAt(
        $this->event,
        new DateTimeImmutable('2026-06-10 12:30:00', $tz),
    );

    self::assertNotNull($next);
    self::assertNotNull($previous);
    // The 12:00 photo is Failed → Next must skip to 13:00, Previous to 11:00.
    self::assertSame('2026-06-10 13:00:00', $next->format('Y-m-d H:i:s'));
    self::assertSame('2026-06-10 11:00:00', $previous->format('Y-m-d H:i:s'));
}
```

- [ ] **Step 2.2: Run the tests, confirm failure**

Run: `vendor/bin/phpunit tests/Integration/Repository/PhotoRepositoryTest.php --filter 'FindPrevious|FindNext|SkipPending'`

Expected: 5 failures with "undefined method" errors.

- [ ] **Step 2.3: Implement both methods**

Add to `src/Repository/PhotoRepository.php`:

```php
public function findPreviousReadyTakenAt(Event $event, DateTimeImmutable $cursor): ?DateTimeImmutable
{
    return $this->findReadyTakenAtRelativeTo($event, $cursor, 'p.takenAt < :cursor', 'DESC');
}

public function findNextReadyTakenAt(Event $event, DateTimeImmutable $cursor): ?DateTimeImmutable
{
    return $this->findReadyTakenAtRelativeTo($event, $cursor, 'p.takenAt > :cursor', 'ASC');
}

private function findReadyTakenAtRelativeTo(
    Event $event,
    DateTimeImmutable $cursor,
    string $predicate,
    string $direction,
): ?DateTimeImmutable {
    $value = $this->createQueryBuilder('p')
        ->select('p.takenAt')
        ->andWhere('p.event = :event')
        ->andWhere('p.status = :status')
        ->andWhere($predicate)
        ->setParameter('event', $event)
        ->setParameter('status', PhotoStatus::Ready)
        ->setParameter('cursor', $cursor)
        ->orderBy('p.takenAt', $direction)
        ->setMaxResults(1)
        ->getQuery()
        ->getOneOrNullResult();

    if ($value === null) {
        return null;
    }

    $takenAt = is_array($value) ? ($value['takenAt'] ?? null) : $value;

    return $takenAt instanceof DateTimeImmutable ? $takenAt : null;
}
```

**Note** — `Caveat: ?t=` URLs lose the day component. For a multi-day event this means `findLastReadyTakenAt` may resolve to a different *day* than expected once the controller composes the URL via `H:i` only. Documented at the call-site (see Task 3). The repo methods themselves are correct — they return real `DateTimeImmutable`s.

- [ ] **Step 2.4: Run the tests, confirm green**

Run: `vendor/bin/phpunit tests/Integration/Repository/PhotoRepositoryTest.php`

Expected: entire file green (existing + 5 new tests from Task 1 + 5 new from Task 2 = 13 tests).

- [ ] **Step 2.5: PHPStan + commit**

Run: `vendor/bin/phpstan analyse src/Repository/PhotoRepository.php`

Expected: no errors.

```bash
git add src/Repository/PhotoRepository.php tests/Integration/Repository/PhotoRepositoryTest.php
git commit -m "62 - add previous/next Ready takenAt cursors on PhotoRepository"
```

---

## Task 3: Controller — wire cursors into the photos action

**Files:**
- Modify: `src/Controller/Public/EventController.php`

This task is non-TDD (a thin wiring change directly observed by Task 4's functional tests). Implement, then prove via existing tests still green.

- [ ] **Step 3.1: Edit `EventController::photos`**

In `src/Controller/Public/EventController.php`, locate the `photos` method (currently around lines 61–84). Update it to compute and pass the four cursors:

```php
#[Route('/e/{slug}/photos', name: 'public_event_photos', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
public function photos(string $slug, Request $request): Response
{
    $event = $this->resolve($slug);

    if ($request->query->has('w')) {
        throw new BadRequestHttpException('Window is no longer configurable per request.');
    }

    $timestamp = $this->resolveTimestamp($request->query->get('t'), $event);

    $start  = $timestamp->modify(sprintf('-%d minutes', Event::WINDOW_BEFORE_MINUTES));
    $end    = $timestamp->modify(sprintf('+%d minutes', Event::WINDOW_AFTER_MINUTES));
    $photos = $this->photos->findReadyInWindow($event, $start, $end);

    // Cross-window navigation cursors (issue #62). All four are `?DateTimeImmutable`;
    // `null` means "no Ready photo exists in that direction" → template renders disabled.
    // Caveat: `?t=` is `HH:mm` only, so for multi-day events the firstAt/lastAt links
    // may collapse back to the start day via `resolveTimestamp`. Acceptable for now
    // (per grooming note in issue #62); follow-up only if real events trip on it.
    $firstAt = $this->photos->findFirstReadyTakenAt($event);
    $lastAt  = $this->photos->findLastReadyTakenAt($event);
    $prevAt  = $this->photos->findPreviousReadyTakenAt($event, $timestamp);
    $nextAt  = $this->photos->findNextReadyTakenAt($event, $timestamp);

    return $this->render('public/event/photos.html.twig', [
        'event'        => $event,
        'timestamp'    => $timestamp,
        'windowBefore' => Event::WINDOW_BEFORE_MINUTES,
        'windowAfter'  => Event::WINDOW_AFTER_MINUTES,
        'photos'       => $photos,
        'capHit'       => count($photos) === self::HARD_CAP,
        'firstAt'      => $firstAt,
        'lastAt'       => $lastAt,
        'prevAt'       => $prevAt,
        'nextAt'       => $nextAt,
    ]);
}
```

No new constructor injections — `$this->photos` is already `PhotoRepository`.

- [ ] **Step 3.2: Confirm existing tests still pass**

Run: `vendor/bin/phpunit tests/Functional/Public/EventPhotosGalleryTest.php`

Expected: green (3 existing tests). New template vars are unused yet but the action's signature is unchanged.

- [ ] **Step 3.3: PHPStan**

Run: `vendor/bin/phpstan analyse src/Controller/Public/EventController.php`

Expected: no errors. (The four new variables are `?DateTimeImmutable`; PHPStan won't complain because Twig templates aren't type-checked.)

- [ ] **Step 3.4: Commit**

```bash
git add src/Controller/Public/EventController.php
git commit -m "62 - compute first/prev/next/last cursors for gallery nav"
```

---

## Task 4: Template — render the four-button nav, then verify functionally

**Files:**
- Modify: `templates/public/event/photos.html.twig`
- Test: `tests/Functional/Public/EventPhotosGalleryTest.php`

### Step 4 — TDD round trip

- [ ] **Step 4.1: Write the failing functional tests**

Append to `tests/Functional/Public/EventPhotosGalleryTest.php` (the file already imports `Event`, `Photo`, `User`, `DateTimeImmutable`, `DateTimeZone`, `EntityManagerInterface`, `Request`, and extends `WebTestCase`):

```php
public function testNavAllDisabledWhenNoReadyPhotos(): void
{
    $client = self::createClient();
    /** @var EntityManagerInterface $em */
    $em = self::getContainer()->get(EntityManagerInterface::class);

    $owner = new User('nav-empty@example.test', 'N');
    $owner->setPassword('x');

    $em->persist($owner);

    $event = new Event(
        'nav-empty',
        'NavEmpty',
        new DateTimeImmutable('2026-06-10 10:00'),
        new DateTimeImmutable('2026-06-10 14:00'),
        $owner,
    );
    $event->setTimezone('UTC');

    $em->persist($event);
    $em->flush();

    $crawler = $client->request(Request::METHOD_GET, '/e/nav-empty/photos?t=12:00');
    $this->assertResponseIsSuccessful();

    foreach (['nav-first', 'nav-prev', 'nav-next', 'nav-last'] as $testId) {
        $node = $crawler->filter(sprintf('[data-testid="%s"]', $testId));
        self::assertSame(1, $node->count(), sprintf('Expected %s element to render', $testId));
        self::assertSame('true', $node->attr('aria-disabled'), sprintf('%s should be disabled', $testId));
        self::assertSame(0, $node->filter('a')->count(), sprintf('%s must not be a clickable <a>', $testId));
    }
}

public function testNavSinglePhotoFirstAndLastShareHrefPrevNextDisabled(): void
{
    $client = self::createClient();
    /** @var EntityManagerInterface $em */
    $em = self::getContainer()->get(EntityManagerInterface::class);

    $owner = new User('nav-single@example.test', 'N');
    $owner->setPassword('x');
    $em->persist($owner);

    $event = new Event(
        'nav-single',
        'NavSingle',
        new DateTimeImmutable('2026-06-10 10:00'),
        new DateTimeImmutable('2026-06-10 14:00'),
        $owner,
    );
    $event->setTimezone('UTC');
    $em->persist($event);

    $only = new Photo($event, str_repeat('a', 64), 'a.jpg', 100);
    $only->markReady(new DateTimeImmutable('2026-06-10 12:15:00', new DateTimeZone('UTC')), 100, 100, 1024);
    $em->persist($only);
    $em->flush();

    // Visit with ?t=12:15 — cursor sits exactly on the only photo.
    $crawler = $client->request(Request::METHOD_GET, '/e/nav-single/photos?t=12:15');
    $this->assertResponseIsSuccessful();

    $first = $crawler->filter('[data-testid="nav-first"]');
    $last  = $crawler->filter('[data-testid="nav-last"]');
    $prev  = $crawler->filter('[data-testid="nav-prev"]');
    $next  = $crawler->filter('[data-testid="nav-next"]');

    // First & Last should each render an <a> pointing at ?t=12:15
    self::assertSame(1, $first->filter('a')->count());
    self::assertSame(1, $last->filter('a')->count());
    self::assertStringContainsString('t=12%3A15', $first->filter('a')->attr('href') ?? '');
    self::assertStringContainsString('t=12%3A15', $last->filter('a')->attr('href') ?? '');

    // Previous & Next disabled (no photo strictly to either side)
    self::assertSame('true', $prev->attr('aria-disabled'));
    self::assertSame('true', $next->attr('aria-disabled'));
    self::assertSame(0, $prev->filter('a')->count());
    self::assertSame(0, $next->filter('a')->count());
}

public function testNavCursorBeforeFirstPhoto(): void
{
    $client = self::createClient();
    /** @var EntityManagerInterface $em */
    $em = self::getContainer()->get(EntityManagerInterface::class);

    $owner = new User('nav-before@example.test', 'N');
    $owner->setPassword('x');
    $em->persist($owner);

    $event = new Event(
        'nav-before',
        'NavBefore',
        new DateTimeImmutable('2026-06-10 10:00'),
        new DateTimeImmutable('2026-06-10 14:00'),
        $owner,
    );
    $event->setTimezone('UTC');
    $em->persist($event);

    $tz = new DateTimeZone('UTC');
    $earliest = new Photo($event, str_repeat('a', 64), 'a.jpg', 100);
    $earliest->markReady(new DateTimeImmutable('2026-06-10 12:00:00', $tz), 100, 100, 1024);
    $latest = new Photo($event, str_repeat('b', 64), 'b.jpg', 100);
    $latest->markReady(new DateTimeImmutable('2026-06-10 13:30:00', $tz), 100, 100, 1024);
    $em->persist($earliest);
    $em->persist($latest);
    $em->flush();

    // ?t=11:30 — strictly before the earliest Ready photo (12:00)
    $crawler = $client->request(Request::METHOD_GET, '/e/nav-before/photos?t=11:30');
    $this->assertResponseIsSuccessful();

    self::assertSame('true', $crawler->filter('[data-testid="nav-first"]')->attr('aria-disabled'));
    self::assertSame('true', $crawler->filter('[data-testid="nav-prev"]')->attr('aria-disabled'));

    $nextHref = $crawler->filter('[data-testid="nav-next"] a')->attr('href');
    $lastHref = $crawler->filter('[data-testid="nav-last"] a')->attr('href');
    self::assertNotNull($nextHref);
    self::assertNotNull($lastHref);
    self::assertStringContainsString('t=12%3A00', $nextHref);
    self::assertStringContainsString('t=13%3A30', $lastHref);
}

public function testNavCursorAfterLastPhoto(): void
{
    $client = self::createClient();
    /** @var EntityManagerInterface $em */
    $em = self::getContainer()->get(EntityManagerInterface::class);

    $owner = new User('nav-after@example.test', 'N');
    $owner->setPassword('x');
    $em->persist($owner);

    $event = new Event(
        'nav-after',
        'NavAfter',
        new DateTimeImmutable('2026-06-10 10:00'),
        new DateTimeImmutable('2026-06-10 14:00'),
        $owner,
    );
    $event->setTimezone('UTC');
    $em->persist($event);

    $tz = new DateTimeZone('UTC');
    $earliest = new Photo($event, str_repeat('a', 64), 'a.jpg', 100);
    $earliest->markReady(new DateTimeImmutable('2026-06-10 12:00:00', $tz), 100, 100, 1024);
    $latest = new Photo($event, str_repeat('b', 64), 'b.jpg', 100);
    $latest->markReady(new DateTimeImmutable('2026-06-10 13:30:00', $tz), 100, 100, 1024);
    $em->persist($earliest);
    $em->persist($latest);
    $em->flush();

    // ?t=13:45 — strictly after the latest Ready photo (13:30)
    $crawler = $client->request(Request::METHOD_GET, '/e/nav-after/photos?t=13:45');
    $this->assertResponseIsSuccessful();

    self::assertSame('true', $crawler->filter('[data-testid="nav-next"]')->attr('aria-disabled'));
    self::assertSame('true', $crawler->filter('[data-testid="nav-last"]')->attr('aria-disabled'));

    $firstHref = $crawler->filter('[data-testid="nav-first"] a')->attr('href');
    $prevHref  = $crawler->filter('[data-testid="nav-prev"] a')->attr('href');
    self::assertNotNull($firstHref);
    self::assertNotNull($prevHref);
    self::assertStringContainsString('t=12%3A00', $firstHref);
    self::assertStringContainsString('t=13%3A30', $prevHref);
}

public function testNavCursorBetweenPhotosAllEnabled(): void
{
    $client = self::createClient();
    /** @var EntityManagerInterface $em */
    $em = self::getContainer()->get(EntityManagerInterface::class);

    $owner = new User('nav-mid@example.test', 'N');
    $owner->setPassword('x');
    $em->persist($owner);

    $event = new Event(
        'nav-mid',
        'NavMid',
        new DateTimeImmutable('2026-06-10 10:00'),
        new DateTimeImmutable('2026-06-10 14:00'),
        $owner,
    );
    $event->setTimezone('UTC');
    $em->persist($event);

    $tz = new DateTimeZone('UTC');
    foreach (['11:00', '12:00', '13:00'] as $i => $hhmm) {
        $p = new Photo($event, str_repeat((string) chr(ord('a') + $i), 64), $hhmm . '.jpg', 100);
        $p->markReady(new DateTimeImmutable('2026-06-10 ' . $hhmm . ':00', $tz), 100, 100, 1024);
        $em->persist($p);
    }
    $em->flush();

    // ?t=12:30 — strictly between 12:00 and 13:00 (note: 12:30 is inside the [12:20, 12:35] window,
    // but neither 12:00 nor 13:00 is, so the window itself will be empty — fine, we're testing nav)
    $crawler = $client->request(Request::METHOD_GET, '/e/nav-mid/photos?t=12:30');
    $this->assertResponseIsSuccessful();

    foreach (['nav-first', 'nav-prev', 'nav-next', 'nav-last'] as $testId) {
        self::assertSame(
            1,
            $crawler->filter(sprintf('[data-testid="%s"] a', $testId))->count(),
            sprintf('%s should be enabled (have an <a>) when cursor sits between photos', $testId),
        );
    }

    self::assertStringContainsString('t=11%3A00', (string) $crawler->filter('[data-testid="nav-first"] a')->attr('href'));
    self::assertStringContainsString('t=12%3A00', (string) $crawler->filter('[data-testid="nav-prev"] a')->attr('href'));
    self::assertStringContainsString('t=13%3A00', (string) $crawler->filter('[data-testid="nav-next"] a')->attr('href'));
    self::assertStringContainsString('t=13%3A00', (string) $crawler->filter('[data-testid="nav-last"] a')->attr('href'));
}
```

**Note on URL-encoding:** Symfony's URL generator encodes `:` as `%3A` in query strings, so we match `t=12%3A00`, not `t=12:00`. The test that produced `?t=12:15` round-trips through the generator the same way.

- [ ] **Step 4.2: Run the tests, confirm failure**

Run: `vendor/bin/phpunit tests/Functional/Public/EventPhotosGalleryTest.php --filter Nav`

Expected: 5 failing tests — most likely failing with `Expected nav-first element to render` because the template doesn't yet emit any `[data-testid="nav-*"]` elements.

- [ ] **Step 4.3: Add the nav block to the template**

In `templates/public/event/photos.html.twig`, insert this block **between** the existing time-filter form (closes at line 21) and the descriptive `<p>` summary (line 23). The exact insertion: directly after `</form>`, before the next `<p class="text-sm …">`.

```twig
{% set nav_targets = {
    'first': firstAt,
    'prev':  prevAt,
    'next':  nextAt,
    'last':  lastAt,
} %}
{% set nav_labels = {
    'first': '« First',
    'prev':  '‹ Previous',
    'next':  'Next ›',
    'last':  'Last »',
} %}

<nav data-testid="cross-window-nav"
     aria-label="Photo timeline navigation"
     class="flex flex-wrap items-center gap-1 text-sm">
    {% for key, target in nav_targets %}
        {% if target is null %}
            <span data-testid="nav-{{ key }}"
                  aria-disabled="true"
                  class="btn btn-sm btn-disabled pointer-events-none opacity-50">
                {{ nav_labels[key] }}
            </span>
        {% else %}
            <span data-testid="nav-{{ key }}">
                <a href="{{ path('public_event_photos', {
                        slug: event.slug,
                        t:    target|date('H:i', event.timezone),
                   }) }}"
                   class="btn btn-sm">
                    {{ nav_labels[key] }}
                </a>
            </span>
        {% endif %}
    {% endfor %}
</nav>
```

**Why a `<span>` wrapper around the disabled state instead of `<button disabled>`:** the spec says "no `href`, visually muted, not clickable." A `<span aria-disabled="true">` matches that — also keeps the wrapper element symmetric (both enabled and disabled cases have a wrapper `<span data-testid="nav-*">`, with the difference being whether it contains an `<a>`), which makes the functional assertions (`filter('a')->count()`) clean.

**Why `data-testid` outside the link:** `$crawler->filter('[data-testid="nav-first"] a')->count()` answers "is this button enabled?" deterministically. With `data-testid` on the `<a>` itself, the empty/disabled case would have to be tested via "this selector returned 0 elements," which is fragile.

- [ ] **Step 4.4: Run the new tests, confirm green**

Run: `vendor/bin/phpunit tests/Functional/Public/EventPhotosGalleryTest.php --filter Nav`

Expected: 5 tests pass.

- [ ] **Step 4.5: Run the entire EventPhotosGalleryTest file**

Run: `vendor/bin/phpunit tests/Functional/Public/EventPhotosGalleryTest.php`

Expected: 8 tests pass (3 pre-existing + 5 new).

- [ ] **Step 4.6: Commit**

```bash
git add templates/public/event/photos.html.twig tests/Functional/Public/EventPhotosGalleryTest.php
git commit -m "62 - render first/prev/next/last gallery nav row"
```

---

## Task 5: Full quality gate

- [ ] **Step 5.1: Run the full test suite**

Run: `vendor/bin/phpunit`

Expected: all green.

- [ ] **Step 5.2: Run GrumPHP (mirrors CI)**

Run: `vendor/bin/grumphp run`

Expected: all tasks pass — `phpstan`, `phpcs`, `phpmnd`, `phpcpd`, `rector`, `securitychecker_roave`, and `doctrine:schema:validate --skip-sync`.

Common landmines and their fixes:
- **`phpmnd` complains about magic numbers in the controller** — none added; we used existing `Event::WINDOW_*` constants. If it flags something, hoist to a class constant.
- **`phpcpd` flags duplication between the two repo helper bodies** — they share param-binding boilerplate but diverge on predicate/direction. If the threshold trips (50 lines / 100 tokens), accept that the private helpers already de-dupe via `findReadyTakenAtOrdered` / `findReadyTakenAtRelativeTo`; if needed, fold the two helpers into one with a nullable predicate.
- **`doctrine:schema:validate`** — no schema changes here, but the gate runs anyway. Must pass.
- **`rector`** — if it suggests using `getOneOrNullResult(AbstractQuery::HYDRATE_*)` or attribute syntax tweaks, accept its diff (`vendor/bin/rector process`) and re-run the suite.

- [ ] **Step 5.3: Commit any quality-gate fixes**

If Step 5.2 surfaced fixups, commit them as a separate small step:

```bash
git add -A
git commit -m "62 - satisfy linters for gallery nav"
```

---

## Self-review checklist (already executed by plan author)

- **Spec coverage:**
  - Behaviour — four buttons rendered above grid: Task 4.3 ✓
  - Targets (First/Last/Prev/Next semantics): Tasks 1, 2, 3 ✓
  - Pivot semantics (vs. resolved `?t=`, not window edges): Task 3 hands `$timestamp` into `findPrevious/Next` ✓
  - Disabled states (zero photos / before earliest / after latest / single photo): Task 4.1 tests cover all four scenarios ✓
  - `H:i` formatting in event timezone: Task 4.3 template uses `target|date('H:i', event.timezone)` ✓
  - Cross-day caveat documented at the controller call site: Task 3.1 comment ✓
- **Placeholders:** none — every code block is complete; assertions match the production template structure.
- **Type consistency:** repository methods return `?DateTimeImmutable`; controller passes them through; Twig nullsafely renders. Names: `findFirstReadyTakenAt` / `findLastReadyTakenAt` / `findPreviousReadyTakenAt` / `findNextReadyTakenAt` consistent across Tasks 1–3. Template var names `firstAt`/`prevAt`/`nextAt`/`lastAt` consistent between Task 3 (controller) and Task 4 (template).
