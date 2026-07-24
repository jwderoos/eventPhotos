# Tagging-Progress Visibility Implementation Plan (#117b)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make attribute-extraction (bib/tag) state observable — a per-photo "tagging… / tagged ✓" indicator plus an event-level "tagged N / M" progress line — without changing what `Ready` means.

**Architecture:** Add a nullable `Photo::$attributesExtractedAt` completion marker, set by `ExtractPhotoAttributesHandler` when it finishes (cleared on re-ingest/retry). The admin grid controller derives event-wide counts + stale/incomplete flags; the row + grid templates render the state via a `data-tagging` attribute and a progress line; the existing `photos_poller_controller.js` is extended to keep live-refreshing while tagging is unfinished. Same branch as the bib recognizer (`feature/117-real-bib-recognizer`); folded under issue #117.

**Tech Stack:** PHP 8.5 / Symfony 8 / Doctrine ORM 3 (migration via diff) / Twig / Turbo + Stimulus. PHPUnit. No new dependencies.

## Global Constraints

- **Branch:** `feature/117-real-bib-recognizer` (do NOT create a new branch; work here — it is not merged until the full functionality satisfies the organizer). Commit messages must contain issue number `117`.
- **This repo does not auto-commit** (CLAUDE.md). The "Commit" steps are for the human operator; the implementing agent must **`git add` (stage) only, never `git commit`**.
- **Do NOT hand-write migrations.** Generate via `bin/console doctrine:migrations:diff`, edit only `getDescription()`. Hand-written index/constraint names drift and break `doctrine:schema:validate` (repo rule / #13).
- **Quality gate is GrumPHP (PHP this time — unlike #117's Python):** `phpstan` level 10, `phpcs` PSR-12 (120 col), `phpmnd` (no magic numbers in `src/` — use named constants; 0/1 are exempt), `phpcpd` (50-line/100-token duplication — do NOT duplicate the stale-detection loop; compute both stale flags in one pass), `rector`, `securitychecker_roave`, `phpunit` (full suite), `doctrine:schema:validate`. Run `vendor/bin/grumphp run` before reporting DONE.
- **Test DB is migration-deterministic:** `SchemaRebuildExtension` drops+recreates `_test` from migrations before the suite. The new column's migration MUST exist (Task 1) or every test fails on schema mismatch. Run PHP/console/vendor-bin on the **host**.
- **Test assertion idioms (rector-enforced):** plain PHPUnit assertions use `$this->assert...` (rector's `PreferPHPUnitThisCallRector` rewrites `self::`→`$this->`); Symfony `WebTestCase` response helpers (`assertResponseIsSuccessful`, `assertSelectorExists`, etc.) stay `self::`. Prefer `createStub` over `createMock`. Run `vendor/bin/rector process --dry-run` over touched files and apply what it reports.
- **Behaviour that must not regress:** `Ready` still means viewable; `ExtractPhotoAttributes` is still best-effort (empty on error, photo stays Ready); the marker is set on *completion regardless of whether any tag was found*.

## File Structure

**Modify:**
- `src/Entity/Photo.php` — add `$attributesExtractedAt` + `markAttributesExtracted()` + `isTaggingPending()`; clear the marker in `resetForRetry()` and `resetForReingest()`.
- `src/MessageHandler/ExtractPhotoAttributesHandler.php` — mark completion at the end of `__invoke`.
- `src/Repository/PhotoRepository.php` — add `countTagged(Event): int`.
- `src/Controller/Admin/PhotoController.php` — `gridFrame`: compute `readyCount`, `taggedCount`, `hasStaleTagging`, `processingIncomplete`; add `STALE_TAGGING_THRESHOLD`.
- `templates/admin/event/_photo_row.html.twig` — `data-tagging` + per-row badge.
- `templates/admin/event/photos_grid.html.twig` — progress line, stale banner, frame `processingIncomplete` data attribute.
- `assets/controllers/photos_poller_controller.js` — extend the keep-polling predicate.

**Create:**
- `migrations/VersionYYYYMMDDHHMMSS.php` — generated (adds the nullable column).

**Test:**
- `tests/Unit/Entity/PhotoTest.php` — marker/helper/reset behaviour.
- `tests/Integration/MessageHandler/ExtractPhotoAttributesHandlerTest.php` — marker set on completion, null on preview failure.
- `tests/Integration/Repository/PhotoRepositoryTest.php` (or existing repo test) — `countTagged`.
- `tests/Functional/Admin/PhotoTaggingProgressTest.php` — grid progress line + `data-tagging` + stale banner + frame flag.

---

### Task 1: `Photo` completion marker + migration

**Files:**
- Modify: `src/Entity/Photo.php`
- Test: `tests/Unit/Entity/PhotoTest.php`
- Create: `migrations/Version*.php` (generated)

**Interfaces:**
- Produces: `Photo::getAttributesExtractedAt(): ?\DateTimeImmutable`; `Photo::markAttributesExtracted(): void` (sets marker + `updatedAt` to now); `Photo::isTaggingPending(): bool` (true iff `status === Ready` && marker null). `resetForRetry()`/`resetForReingest()` clear the marker.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Entity/PhotoTest.php` (follow the file's existing construction helpers for building a `Photo` and driving it to `Ready`):
```php
public function testNewReadyPhotoIsTaggingPending(): void
{
    $photo = $this->readyPhoto();               // status Ready, marker still null
    $this->assertNull($photo->getAttributesExtractedAt());
    $this->assertTrue($photo->isTaggingPending());
}

public function testMarkAttributesExtractedSetsMarkerAndClearsPending(): void
{
    $photo = $this->readyPhoto();
    $photo->markAttributesExtracted();
    $this->assertInstanceOf(\DateTimeImmutable::class, $photo->getAttributesExtractedAt());
    $this->assertFalse($photo->isTaggingPending());
}

public function testPendingAndFailedAreNotTaggingPending(): void
{
    $pending = $this->pendingPhoto();           // status Pending
    $this->assertFalse($pending->isTaggingPending());
    $failed = $this->readyPhoto();
    $failed->markFailed('boom');                // Ready cannot fail; build a failed one per file helpers
    // If markFailed requires Pending, construct a fresh Pending->Failed photo via the file's helper instead.
    $this->assertFalse($failed->isTaggingPending());
}

public function testResetForReingestClearsMarker(): void
{
    $photo = $this->readyPhoto();
    $photo->markAttributesExtracted();
    $photo->resetForReingest();
    $this->assertNull($photo->getAttributesExtractedAt());
    $this->assertSame(\App\Entity\PhotoStatus::Pending, $photo->getStatus());
}

public function testResetForRetryClearsMarker(): void
{
    $photo = $this->failedPhoto();              // status Failed, per file helper
    $photo->resetForRetry();
    $this->assertNull($photo->getAttributesExtractedAt());
}
```
Match the existing `PhotoTest` construction pattern (use whatever helper/constructor the file already uses to reach `Ready`/`Failed`; the assertions above are the contract). If no `readyPhoto()/pendingPhoto()/failedPhoto()` helpers exist, inline the construction the way sibling tests in this file do.

- [ ] **Step 2: Run to verify it fails**

Run: `SKIP_SCHEMA_REBUILD=1 vendor/bin/phpunit tests/Unit/Entity/PhotoTest.php`
Expected: FAIL — `markAttributesExtracted`/`isTaggingPending`/`getAttributesExtractedAt` undefined.

- [ ] **Step 3: Implement on `Photo`**

Add the mapped field near the other `DATETIME_IMMUTABLE, nullable` columns (e.g. beside `takenAt`):
```php
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $attributesExtractedAt = null;
```
Add accessors/behaviour (place near `getUpdatedAt()` / the reset methods):
```php
    public function getAttributesExtractedAt(): ?DateTimeImmutable
    {
        return $this->attributesExtractedAt;
    }

    public function markAttributesExtracted(): void
    {
        $now = new DateTimeImmutable();
        $this->attributesExtractedAt = $now;
        $this->updatedAt = $now;
    }

    public function isTaggingPending(): bool
    {
        return $this->status === PhotoStatus::Ready && $this->attributesExtractedAt === null;
    }
```
In **both** `resetForRetry()` and `resetForReingest()`, add before setting `$this->status = PhotoStatus::Pending;`:
```php
        $this->attributesExtractedAt = null;
```

- [ ] **Step 4: Run to verify it passes**

Run: `SKIP_SCHEMA_REBUILD=1 vendor/bin/phpunit tests/Unit/Entity/PhotoTest.php`
Expected: PASS.

- [ ] **Step 5: Generate the migration**

Run: `bin/console doctrine:migrations:diff`
Expected: a new `migrations/Version*.php` adding `attributes_extracted_at` (nullable timestamp) to `photos`. Edit only `getDescription()` to `'Add photos.attributes_extracted_at tagging-completion marker (#117b)'`. Do NOT touch the generated SQL.

- [ ] **Step 6: Verify schema + full unit run**

Run: `bin/console doctrine:migrations:migrate --no-interaction` (dev DB), then `bin/console doctrine:schema:validate` → expect "in sync". Then `vendor/bin/phpunit tests/Unit/Entity/PhotoTest.php` (no SKIP flag, so the test DB rebuilds from migrations) → PASS.

- [ ] **Step 7: Commit (human runs)**

```bash
git add src/Entity/Photo.php tests/Unit/Entity/PhotoTest.php migrations/Version*.php
git commit -m "117 - Photo.attributesExtractedAt tagging-completion marker + reset clearing"
```

---

### Task 2: Handler sets the marker on completion

**Files:**
- Modify: `src/MessageHandler/ExtractPhotoAttributesHandler.php`
- Test: `tests/Integration/MessageHandler/ExtractPhotoAttributesHandlerTest.php`

**Interfaces:**
- Consumes: `Photo::markAttributesExtracted()` (Task 1), the existing `FakeAttributeExtractorClient` (test).
- Produces: after a successful `__invoke`, the photo's `attributesExtractedAt` is non-null; on early return (preview unreadable) it stays null.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Integration/MessageHandler/ExtractPhotoAttributesHandlerTest.php` (mirror the existing test setup — real DB via `dama` transaction, `FakeAttributeExtractorClient` bound under `when@test`, a Ready photo with a preview written to `photo_previews_storage`):
```php
public function testMarksAttributesExtractedOnSuccessWithTags(): void
{
    [$photo] = $this->readyPhotoWithPreview();          // per existing helper
    $this->fakeClient->setNext(new ExtractedAttributes([], [], [], [new AttributeScore('1234', 0.95)]));
    ($this->handler)(new ExtractPhotoAttributes((int) $photo->getId()));
    $this->em->refresh($photo);
    $this->assertNotNull($photo->getAttributesExtractedAt());
}

public function testMarksAttributesExtractedOnSuccessWithNoTags(): void
{
    [$photo] = $this->readyPhotoWithPreview();
    $this->fakeClient->setNext(ExtractedAttributes::empty());
    ($this->handler)(new ExtractPhotoAttributes((int) $photo->getId()));
    $this->em->refresh($photo);
    $this->assertNotNull($photo->getAttributesExtractedAt());  // "done, found nothing" still marks done
}

public function testDoesNotMarkWhenPreviewMissing(): void
{
    [$photo] = $this->readyPhotoWithoutPreview();       // no bytes on the previews disk
    ($this->handler)(new ExtractPhotoAttributes((int) $photo->getId()));
    $this->em->refresh($photo);
    $this->assertNull($photo->getAttributesExtractedAt());     // stays "tagging pending"
}
```
Use the concrete construction the existing handler test already uses; the assertions are the contract. `ExtractedAttributes`/`AttributeScore` are in `App\Service\Photo`.

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/MessageHandler/ExtractPhotoAttributesHandlerTest.php`
Expected: FAIL — `getAttributesExtractedAt()` is null after a successful run (marker not set yet).

- [ ] **Step 3: Implement**

In `ExtractPhotoAttributesHandler::__invoke`, the success path currently ends with `$this->em->flush();` after the attribute-persist loops. Immediately after that flush add:
```php
        $photo->markAttributesExtracted();
        $this->em->flush();
```
Leave the early `return`s (photo missing, not Ready, preview unreadable) untouched — the marker must NOT be set on those paths.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/MessageHandler/ExtractPhotoAttributesHandlerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit (human runs)**

```bash
git add src/MessageHandler/ExtractPhotoAttributesHandler.php tests/Integration/MessageHandler/ExtractPhotoAttributesHandlerTest.php
git commit -m "117 - mark attributesExtractedAt when extraction handler completes"
```

---

### Task 3: `countTagged` repository method + grid controller context

**Files:**
- Modify: `src/Repository/PhotoRepository.php`
- Modify: `src/Controller/Admin/PhotoController.php`
- Test: `tests/Integration/Repository/PhotoRepositoryTest.php` (or the existing repo test file)

**Interfaces:**
- Consumes: `Photo::isTaggingPending()`, `PhotoRepository::countReady`, `countByStatus` (existing).
- Produces: `PhotoRepository::countTagged(Event $event): int` (Ready photos with `attributesExtractedAt` not null); `gridFrame` renders with extra context keys `readyCount`, `taggedCount`, `hasStaleTagging`, `processingIncomplete`.

- [ ] **Step 1: Write the failing repository test**

Add to the repository integration test (mirror existing repo-test setup):
```php
public function testCountTaggedCountsOnlyReadyExtracted(): void
{
    $event = $this->persistEvent();
    $this->persistReadyPhoto($event, extracted: true);
    $this->persistReadyPhoto($event, extracted: true);
    $this->persistReadyPhoto($event, extracted: false);   // ready, not yet tagged
    $this->persistPendingPhoto($event);                    // pending
    $this->em->flush();
    $this->assertSame(2, $this->repository->countTagged($event));
}
```
Use the existing helpers for persisting events/photos; add an `extracted` toggle that calls `markAttributesExtracted()` when true.

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter testCountTaggedCountsOnlyReadyExtracted`
Expected: FAIL — `countTagged` undefined.

- [ ] **Step 3: Implement `countTagged`**

In `PhotoRepository`, mirroring `countReady`'s query shape:
```php
    public function countTagged(Event $event): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.event = :event')
            ->andWhere('p.status = :ready')
            ->andWhere('p.attributesExtractedAt IS NOT NULL')
            ->setParameter('event', $event)
            ->setParameter('ready', PhotoStatus::Ready)
            ->getQuery()
            ->getSingleScalarResult();
    }
```
(Match `countReady`'s exact parameter/enum-binding style in this file.)

- [ ] **Step 4: Extend `gridFrame`**

In `PhotoController`, add the threshold constant beside `STALE_PENDING_THRESHOLD`:
```php
    private const string STALE_TAGGING_THRESHOLD = '-5 minutes';
```
In `gridFrame`, compute both stale flags in the **single existing `$photos` loop** (do not add a second loop — phpcpd). Replace the current loop with:
```php
        $hasStalePending = false;
        $hasStaleTagging = false;
        $pendingCutoff   = new DateTimeImmutable(self::STALE_PENDING_THRESHOLD);
        $taggingCutoff   = new DateTimeImmutable(self::STALE_TAGGING_THRESHOLD);
        foreach ($photos as $p) {
            if ($p->getStatus() === PhotoStatus::Pending && $p->getCreatedAt() < $pendingCutoff) {
                $hasStalePending = true;
            }
            if ($p->isTaggingPending() && $p->getUpdatedAt() < $taggingCutoff) {
                $hasStaleTagging = true;
            }
        }

        $readyCount  = $this->photos->countReady($event);
        $taggedCount = $this->photos->countTagged($event);
        $pendingCount = $this->photos->countByStatus($event, PhotoStatus::Pending);
        $processingIncomplete = $pendingCount > 0 || $taggedCount < $readyCount;
```
Add to the `render(...)` context array:
```php
            'readyCount'           => $readyCount,
            'taggedCount'          => $taggedCount,
            'hasStaleTagging'      => $hasStaleTagging,
            'processingIncomplete' => $processingIncomplete,
```

- [ ] **Step 5: Write + run the controller-context assertion (functional)**

This is covered by Task 4's functional test (rendered output). For now verify wiring:
Run: `bin/console lint:container && vendor/bin/phpstan analyse src/Repository/PhotoRepository.php src/Controller/Admin/PhotoController.php`
Expected: clean.

- [ ] **Step 6: Run repository test**

Run: `vendor/bin/phpunit --filter testCountTaggedCountsOnlyReadyExtracted`
Expected: PASS.

- [ ] **Step 7: Commit (human runs)**

```bash
git add src/Repository/PhotoRepository.php src/Controller/Admin/PhotoController.php tests/Integration/Repository/PhotoRepositoryTest.php
git commit -m "117 - grid: event-wide tagged/ready counts + stale-tagging + incomplete flag"
```

---

### Task 4: Frontend — per-row tagging state, progress line, stale banner, poller

**Files:**
- Modify: `templates/admin/event/_photo_row.html.twig`
- Modify: `templates/admin/event/photos_grid.html.twig`
- Modify: `assets/controllers/photos_poller_controller.js`
- Test: `tests/Functional/Admin/PhotoTaggingProgressTest.php`

**Interfaces:**
- Consumes: `Photo::isTaggingPending()`; grid context `readyCount`, `taggedCount`, `hasStaleTagging`, `processingIncomplete` (Task 3).
- Produces: Ready rows carry `data-tagging="pending|done"` + a visible badge; the grid shows a "Tagging N / M" line and a stale banner; the frame carries a `processingIncomplete` marker the poller reads.

- [ ] **Step 1: Write the failing functional test**

`tests/Functional/Admin/PhotoTaggingProgressTest.php` (mirror `PhotoReingestUiTest` for auth + fixtures):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\PhotoStatus;
// ... use the same base WebTestCase + fixture helpers as PhotoReingestUiTest

final class PhotoTaggingProgressTest extends /* same base */
{
    public function testGridShowsTaggingProgressAndPerRowState(): void
    {
        $event = $this->createEventOwnedByLoggedInOrganizer();
        $tagged  = $this->createReadyPhoto($event, extracted: true);
        $untagged = $this->createReadyPhoto($event, extracted: false);

        $client = $this->clientLoggedInAsOwner();
        $crawler = $client->request('GET', sprintf('/admin/events/%d/photos/grid', $event->getId()));
        // (use the actual gridFrame route path/name from PhotoController)

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="tagging-progress"]', '1 / 2');
        self::assertSelectorExists(sprintf('tr[data-photo-id="%d"][data-tagging="done"]', $tagged->getId()));
        self::assertSelectorExists(sprintf('tr[data-photo-id="%d"][data-tagging="pending"]', $untagged->getId()));
    }
}
```
Use the real gridFrame route (check `PhotoController::gridFrame`'s `#[Route]` name/path) and the project's existing functional-fixture helpers. The assertions (progress text `taggedCount / readyCount`, per-row `data-tagging`) are the contract.

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Admin/PhotoTaggingProgressTest.php`
Expected: FAIL — no `data-tagging` / no progress element yet.

- [ ] **Step 3: `_photo_row.html.twig` — per-row tagging state**

On the `<tr>` (which already has `data-photo-id` and `data-status`), add a tagging data attribute for Ready rows:
```twig
<tr data-photo-id="{{ photo.id }}" data-status="{{ photo.status.value }}"
    {% if photo.status.value == 'ready' %}data-tagging="{{ photo.isTaggingPending ? 'pending' : 'done' }}"{% endif %}>
```
Next to the existing status badge (around the `badge` block), add a tagging badge for Ready rows:
```twig
        {% if photo.status.value == 'ready' %}
            {% if photo.isTaggingPending %}
                <span class="badge badge-ghost gap-1"><span class="loading loading-spinner loading-xs"></span>tagging…</span>
            {% else %}
                <span class="badge badge-success badge-outline">tagged ✓</span>
            {% endif %}
        {% endif %}
```
(Use the row's existing markup conventions/classes; keep within PSR-agnostic Twig — Tailwind/daisyUI classes as used elsewhere.)

- [ ] **Step 4: `photos_grid.html.twig` — progress line, stale banner, frame flag**

Put the `processingIncomplete` flag on the `<turbo-frame>` so the poller can read it:
```twig
<turbo-frame id="photos-grid" data-processing-incomplete="{{ processingIncomplete ? '1' : '0' }}">
```
After the existing `hasStalePending` alert, add the stale-tagging banner and the progress line:
```twig
    {% if hasStaleTagging %}
        <div class="alert alert-warning my-4">
            Some photos are taking a long time to tag. Is the worker and inference service running?
        </div>
    {% endif %}
    {% if readyCount > 0 %}
        <p class="text-sm text-base-content/70 my-2" data-testid="tagging-progress">
            Tagging {{ taggedCount }} / {{ readyCount }}
        </p>
    {% endif %}
```

- [ ] **Step 5: `photos_poller_controller.js` — extend the keep-polling predicate**

In `scheduleIfNeeded()`, replace the pending-only check:
```js
        const pending = this.element.querySelector('[data-status="pending"]');
        if (!pending) {
            return;
        }
```
with a predicate that also covers tagging-in-progress and the event-wide incomplete flag:
```js
        const stillWorking =
            this.element.querySelector('[data-status="pending"], [data-tagging="pending"]') !== null ||
            this.element.getAttribute('data-processing-incomplete') === '1';
        if (!stillWorking) {
            return;
        }
```
(Leave the `poll()` / timer logic unchanged.)

- [ ] **Step 6: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Admin/PhotoTaggingProgressTest.php`
Expected: PASS.

- [ ] **Step 7: Build assets + manual verify the live refresh**

Run: `bin/console asset-map:compile` (or the project's asset step) so the Stimulus change is served. Then, per the `verify` skill / manually: load the grid for an event mid-(re)ingest and confirm the "Tagging N / M" line advances and the per-row badge flips to "tagged ✓" without a manual reload, and that polling stops once `taggedCount == readyCount` and no pending rows remain.

- [ ] **Step 8: Commit (human runs)**

```bash
git add templates/admin/event/_photo_row.html.twig templates/admin/event/photos_grid.html.twig assets/controllers/photos_poller_controller.js tests/Functional/Admin/PhotoTaggingProgressTest.php
git commit -m "117 - grid UI: per-row tagging state + progress line + stale banner + poller"
```

---

## Self-Review

**1. Spec coverage** (against `2026-07-17-117b-tagging-progress-visibility-design.md`):
- Separate marker `attributesExtractedAt` + `markAttributesExtracted` + `isTaggingPending` + reset clearing → Task 1. ✓
- Handler sets marker on completion (found-tags AND found-nothing), null on preview failure → Task 2. ✓
- Event-wide `taggedCount`/`readyCount`, `hasStaleTagging` (updatedAt-based, not createdAt), `processingIncomplete` → Task 3. ✓
- Per-row `data-tagging` + badge, progress line, stale banner, frame flag, poller extension → Task 4. ✓
- Migration + schema-validate → Task 1. ✓
- `Ready` meaning unchanged; best-effort extraction unchanged → no change to markReady / handler early-returns. ✓

**2. Placeholder scan:** No TBD/TODO. The test snippets defer to "the file's existing fixture helpers" because the exact helper names live in those files — the *assertions* (the contract) are concrete; implementers mirror sibling tests for construction. Route path in Task 4 is read from `gridFrame`'s existing `#[Route]`.

**3. Type/consistency:** `attributesExtractedAt` (`?DateTimeImmutable`), `markAttributesExtracted()`, `isTaggingPending()`, `countTagged(Event): int`, context keys (`readyCount`, `taggedCount`, `hasStaleTagging`, `processingIncomplete`), and the `data-tagging` / `data-processing-incomplete` attribute names are used identically across Tasks 1–4.

**4. phpmnd / phpcpd:** `-5 minutes` lives in the `STALE_TAGGING_THRESHOLD` constant (no magic literal); both stale flags computed in one loop (no duplicated block). Poll interval (5000) is pre-existing JS, not phpmnd-scoped.

## Notes for the executor
- PHP feature → GrumPHP applies. Controller must spot-run `vendor/bin/grumphp run` (and `phpstan`/`rector --dry-run`) after each task; implementer green reports are not fully trusted (memory: verify-grumphp-after-subagents).
- Migration ordering: Task 1's migration must land before any test run that rebuilds the schema, or the whole suite fails on schema mismatch.
- Verify the real `gridFrame` route name/path before writing Task 4's functional request.
