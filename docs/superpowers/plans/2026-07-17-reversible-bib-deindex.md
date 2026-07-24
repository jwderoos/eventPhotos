# Reversible Bib De-index Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make bib de-index reversible and move its management (de-index, undo, list) onto the Tags overview page.

**Architecture:** Suppression stops being destructive. `PhotoAttribute` bib rows are never deleted and are always written by extraction; `BibSuppression(event, bibNumber)` becomes a reversible flag consulted only at *read* time (public search + the admin Tags overview). Undo = delete one `BibSuppression` row → tags reappear instantly.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3 (DQL), PHPUnit 13, Twig/Tailwind.

## Global Constraints

- PHP attributes only — no annotations.
- No hand-written migrations; there is **no schema change** in this plan (`BibSuppression` keeps its current shape).
- Do NOT author `git commit`. Per the user's standing preference, each task ends by running the quality gate and staging; the **user** commits. A proposed one-line message (with a GitHub issue number, `117`) is given per task.
- After any task touching PHP, the gate is `vendor/bin/grumphp run` (phpstan level 10, phpcs PSR-12, phpmnd, phpcpd, rector, phpunit, `doctrine:schema:validate`). For tight loops use `vendor/bin/phpunit --filter <name>`.
- Existing behavior being changed on purpose: extraction previously skipped suppressed bibs and suppress previously deleted rows. Tests asserting the old behavior are updated in the same task that changes it — this is expected, not a regression.

---

### Task 1: Public search excludes suppressed bibs (read overlay)

Under the new model a suppressed bib still has `PhotoAttribute` rows, so the existing inner join would return its photos. A visitor searching a de-indexed bib must get **zero** results — enforced in the query so it holds regardless of caller.

**Files:**
- Modify: `src/Repository/PhotoRepository.php` (the `$filter->bib` branch in `searchReady`, currently lines 97-106)
- Test: `tests/Integration/Repository/PhotoRepositorySearchTest.php`

**Interfaces:**
- Consumes: `PhotoRepository::searchReady(Event $event, PhotoAttributeFilter $filter, int $limit): list<Photo>` (unchanged signature); `App\Entity\BibSuppression($event, $bibNumber)`.
- Produces: no signature change — behavior change only.

- [ ] **Step 1: Write the failing test**

Add to `tests/Integration/Repository/PhotoRepositorySearchTest.php` (note the new import at the top: `use App\Entity\BibSuppression;`):

```php
public function testSuppressedBibIsExcludedFromSearch(): void
{
    $event = PhotoFixtures::event($this->em);
    $photo = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:00:00');
    PhotoFixtures::tagBib($this->em, $photo, '1423');
    $this->em->persist(new BibSuppression($event, '1423'));
    $this->em->flush();

    // Bib row still exists, but suppression hides it from search.
    $this->assertCount(0, $this->repo->searchReady($event, new PhotoAttributeFilter(bib: '1423'), 200));
}

public function testNonSuppressedBibStillMatches(): void
{
    $event = PhotoFixtures::event($this->em);
    $photo = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:00:00');
    PhotoFixtures::tagBib($this->em, $photo, '1423');
    $this->em->persist(new BibSuppression($event, '9999')); // a different bib suppressed
    $this->em->flush();

    $this->assertCount(1, $this->repo->searchReady($event, new PhotoAttributeFilter(bib: '1423'), 200));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testSuppressedBibIsExcludedFromSearch`
Expected: FAIL — returns 1 photo (no exclusion yet), asserted 0.

- [ ] **Step 3: Implement the query exclusion**

In `src/Repository/PhotoRepository.php`, replace the `$filter->bib` branch:

```php
        if ($filter->bib !== null) {
            $qb->innerJoin(
                PhotoAttribute::class,
                'pab',
                Join::WITH,
                'pab.photo = p AND pab.type = :bibType AND pab.value = :bib',
            )
                ->andWhere(
                    'NOT EXISTS ('
                    . 'SELECT 1 FROM App\Entity\BibSuppression bs '
                    . 'WHERE bs.event = :event AND bs.bibNumber = :bib'
                    . ')'
                )
                ->setParameter('bibType', PhotoAttributeType::Bib)
                ->setParameter('bib', $filter->bib);
        }
```

(`:event` is already bound at the top of `searchReady`. `BibSuppression` is referenced by FQCN in the DQL string, matching existing style — no `use` import needed.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Integration/Repository/PhotoRepositorySearchTest.php`
Expected: PASS (including the pre-existing `testBibExactMatch`).

- [ ] **Step 5: Gate + stage**

Run: `vendor/bin/grumphp run`
Then `git add src/Repository/PhotoRepository.php tests/Integration/Repository/PhotoRepositorySearchTest.php`.
Proposed commit: `117 - bib de-index read overlay: exclude suppressed bibs from public search`

---

### Task 2: Extraction always persists bibs (write path stops checking suppression)

For undo to be lossless, a bib must be stored even while suppressed (a photo ingested during an active suppression must reappear on undo). Suppression moves entirely out of the write path.

**Files:**
- Modify: `src/MessageHandler/ExtractPhotoAttributesHandler.php` — `bibIsIndexable()` (currently lines 110-123) and remove the now-unused `BibSuppressionRepository` dependency (constructor line 33, import line 12).
- Test: `tests/Integration/MessageHandler/ExtractPhotoAttributesHandlerTest.php`

**Interfaces:**
- Consumes: `ExtractPhotoAttributesHandler` invoked via `(new ExtractPhotoAttributes($photoId))`.
- Produces: bibs meeting `Event::isBibIndexingEnabled()` + `BIB_MIN_CONFIDENCE` are always persisted; suppression no longer affects extraction.

- [ ] **Step 1: Update the two tests that asserted the old skip behavior**

In `tests/Integration/MessageHandler/ExtractPhotoAttributesHandlerTest.php`, replace `testBibSkippedWhenSuppressed` (lines 188-200) with:

```php
public function testBibWrittenEvenWhenSuppressed(): void
{
    // Suppression is a read-time overlay now; extraction still stores the bib
    // so undo (removing the suppression) is lossless.
    $this->event->enableBibIndexing();
    $this->em->persist(new BibSuppression($this->event, '1423'));
    $this->em->flush();

    $photo = $this->seedReadyPhoto('ee');
    $this->client->setNext($this->response(bibs: [['1423', 0.99]]));

    ($this->handler)(new ExtractPhotoAttributes($photo->getId() ?? 0));

    $this->assertSame(['1423'], $this->valuesOfType($photo, PhotoAttributeType::Bib));
}
```

Replace `testSuppressionSurvivesReingest` (lines 214-232) with:

```php
public function testReingestRewritesBibEvenWhenSuppressed(): void
{
    $this->event->enableBibIndexing();
    $photo = $this->seedReadyPhoto('a1');
    $this->client->setNext($this->response(bibs: [['1423', 0.99]]));

    // First extraction writes the bib.
    ($this->handler)(new ExtractPhotoAttributes($photo->getId() ?? 0));
    $this->assertSame(['1423'], $this->valuesOfType($photo, PhotoAttributeType::Bib));

    // Organizer de-indexes (reversible overlay): suppression flag only, no row deletion.
    $this->em->persist(new BibSuppression($this->event, '1423'));
    $this->em->flush();

    // Re-ingest re-dispatches extraction; the bib row is re-written (search hides it).
    ($this->handler)(new ExtractPhotoAttributes($photo->getId() ?? 0));
    $this->assertSame(['1423'], $this->valuesOfType($photo, PhotoAttributeType::Bib));
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter testBibWrittenEvenWhenSuppressed`
Expected: FAIL — handler still skips the suppressed bib, so the result is `[]` not `['1423']`.

- [ ] **Step 3: Remove the suppression check from the write path**

In `src/MessageHandler/ExtractPhotoAttributesHandler.php`, change `bibIsIndexable()` to:

```php
    private function bibIsIndexable(Photo $photo, AttributeScore $attribute): bool
    {
        $event = $photo->getEvent();

        if (!$event->isBibIndexingEnabled()) {
            return false;
        }

        return $attribute->confidence >= self::BIB_MIN_CONFIDENCE;
    }
```

Remove the constructor promotion `private BibSuppressionRepository $suppressions,` (line 33) and the `use App\Repository\BibSuppressionRepository;` import (line 12). If `Event $event` becomes unused after the edit, keep the assignment only if still referenced by `isBibIndexingEnabled()` — it is, so leave it.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Integration/MessageHandler/ExtractPhotoAttributesHandlerTest.php`
Expected: PASS (including `testBibSkippedWhenToggleOff`, `testBibSkippedBelowConfidenceThreshold`, `testBibWrittenWhenAllConditionsMet`).

- [ ] **Step 5: Gate + stage**

Run: `vendor/bin/grumphp run`
Then `git add src/MessageHandler/ExtractPhotoAttributesHandler.php tests/Integration/MessageHandler/ExtractPhotoAttributesHandlerTest.php`.
Proposed commit: `117 - bib de-index: extraction always persists bibs; suppression is read-time only`

---

### Task 3: Non-destructive suppress, moved into PhotoTagController; drop dead delete

Suppress stops deleting `PhotoAttribute` rows and moves to `PhotoTagController` (which owns the Tags page). The now-dead `deleteBibForEvent` is removed. The route URL and CSRF id are unchanged; only the redirect target changes to the Tags page.

**Files:**
- Modify: `src/Controller/Admin/PhotoTagController.php` — add write deps + the `suppressBib` action.
- Modify: `src/Controller/Admin/PhotoController.php` — remove `suppressBib` (lines 359-398), remove now-unused constructor deps `$bibSuppressions` and `$photoAttributes` (lines 51-52) and the unused imports (`BibSuppression` line 11, `BibSuppressionRepository` line 16, `PhotoAttributeRepository` line 17) if unreferenced elsewhere in the file.
- Modify: `src/Repository/PhotoAttributeRepository.php` — delete `deleteBibForEvent()` (lines 106-124).
- Test: `tests/Functional/Admin/BibSuppressionActionTest.php`
- Test: `tests/Integration/Repository/PhotoAttributeRepositoryTest.php` (remove `deleteBibForEvent` coverage)

**Interfaces:**
- Consumes: `BibSuppressionRepository::isSuppressed(Event, string): bool`; `AuditContext::set(string, mixed)`; `EventVoter::EDIT`; `Event::MAX`… (uses `BibSuppression::MAX_BIB_NUMBER_LENGTH`).
- Produces: route `admin_bib_suppress` (POST `/admin/events/{id}/bib-suppressions`, CSRF `suppress_bib_<id>`) now lives on `PhotoTagController`, inserts a suppression without deleting rows, redirects to `admin_photo_tags`.

- [ ] **Step 1: Update the functional test for non-destructive suppress**

In `tests/Functional/Admin/BibSuppressionActionTest.php`, replace `testSuppressBibDeletesStoredBibTags` (lines 135-161) with:

```php
public function testSuppressBibKeepsStoredBibTags(): void
{
    [$user, $event] = $this->makeOrganizerWithEvent();

    $photo = new Photo($event, str_pad('c1', 64, '0'), 'p.jpg', 1000);
    $this->em->persist($photo);
    $this->em->flush();

    $this->em->persist(new PhotoAttribute($photo, PhotoAttributeType::Bib, '1423', 0.99));
    $this->em->persist(new PhotoAttribute($photo, PhotoAttributeType::ClothingColor, 'orange', 0.9));
    $this->em->flush();

    $this->client->loginUser($user);
    $token = $this->primeCsrfToken('suppress_bib_' . $event->getId());

    $this->client->request(Request::METHOD_POST, '/admin/events/' . $event->getId() . '/bib-suppressions', [
        'bibNumber' => '1423',
        '_token'    => $token,
    ]);

    self::assertResponseRedirects();

    /** @var PhotoAttributeRepository $repo */
    $repo = self::getContainer()->get(PhotoAttributeRepository::class);
    // Reversible overlay: the bib row is NOT deleted, only flagged suppressed.
    $this->assertCount(1, $repo->findBy(['type' => PhotoAttributeType::Bib, 'value' => '1423']));
    $this->assertCount(1, $repo->findBy(['type' => PhotoAttributeType::ClothingColor, 'value' => 'orange']));

    /** @var BibSuppressionRepository $suppressions */
    $suppressions = self::getContainer()->get(BibSuppressionRepository::class);
    $this->assertTrue($suppressions->isSuppressed($event, '1423'));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testSuppressBibKeepsStoredBibTags`
Expected: FAIL — current controller deletes the bib row, so the count is 0 not 1.

- [ ] **Step 3: Add `suppressBib` to `PhotoTagController` and its dependencies**

In `src/Controller/Admin/PhotoTagController.php`, add imports:

```php
use App\Audit\Attribute\Audited;
use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Entity\BibSuppression;
use App\Repository\BibSuppressionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
```

Replace the constructor:

```php
    public function __construct(
        private readonly PhotoAttributeRepository $attributes,
        private readonly BibSuppressionRepository $bibSuppressions,
        private readonly EntityManagerInterface $em,
        private readonly AuditContext $audit,
    ) {
    }
```

Add the action (moved verbatim from `PhotoController`, minus the `deleteBibForEvent` call, redirecting to the Tags page):

```php
    #[Route(
        '/admin/events/{id}/bib-suppressions',
        name: 'admin_bib_suppress',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[Audited(AuditAction::EventBibSuppress, targetParam: 'id', targetType: 'Event')]
    public function suppressBib(Event $event, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);
        $this->assertCsrf($request, 'suppress_bib_' . $event->getId());

        $bibNumber = trim((string) $request->request->get('bibNumber'));
        if ($bibNumber === '') {
            $this->addFlash('error', 'Enter a bib number to de-index.');

            return $this->redirectToRoute('admin_photo_tags', ['id' => $event->getId()]);
        }

        if (mb_strlen($bibNumber) > BibSuppression::MAX_BIB_NUMBER_LENGTH) {
            $this->addFlash('error', 'Bib number is too long.');

            return $this->redirectToRoute('admin_photo_tags', ['id' => $event->getId()]);
        }

        // Reversible overlay: flag only, never delete PhotoAttribute rows.
        if (!$this->bibSuppressions->isSuppressed($event, $bibNumber)) {
            $this->em->persist(new BibSuppression($event, $bibNumber));
            $this->em->flush();
        }

        $this->audit->set('suppressed_bib', $bibNumber);
        $this->addFlash('success', sprintf('Bib %s is no longer indexed.', $bibNumber));

        return $this->redirectToRoute('admin_photo_tags', ['id' => $event->getId()]);
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid($tokenId, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
```

- [ ] **Step 4: Remove `suppressBib` and dead deps from `PhotoController`; drop `deleteBibForEvent`**

In `src/Controller/Admin/PhotoController.php`: delete the whole `suppressBib` method (lines 359-398). Remove constructor deps `private readonly BibSuppressionRepository $bibSuppressions,` and `private readonly PhotoAttributeRepository $photoAttributes,`. Remove the imports `use App\Entity\BibSuppression;`, `use App\Repository\BibSuppressionRepository;`, `use App\Repository\PhotoAttributeRepository;` (verify none are referenced elsewhere in the file first with a grep — Step 4 relies on Task-2/3 having removed the only usages).

In `src/Repository/PhotoAttributeRepository.php`: delete `deleteBibForEvent()` (lines 106-124). If `PhotoAttributeType` import becomes unused after deletion, remove it too (it is still used by `aggregateForEvent`'s enum handling — leave it).

- [ ] **Step 5: Remove the `deleteBibForEvent` integration coverage**

In `tests/Integration/Repository/PhotoAttributeRepositoryTest.php`, delete both methods exercising `deleteBibForEvent` — `testDeleteBibForEventRemovesThatBibAcrossAllPhotosOnly` (~line 63) and `testDeleteBibForEventDoesNotTouchOtherEvents` (~line 87). Grep the file afterward to confirm no `deleteBibForEvent` references remain; leave the rest untouched.

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Functional/Admin/BibSuppressionActionTest.php tests/Integration/Repository/PhotoAttributeRepositoryTest.php`
Expected: PASS (existing `testSuppressBibRequiresCsrf`, `testSuppressBibInsertsRow`, `testSuppressingSameBibTwiceIsIdempotent` still green — same URL + CSRF id).

- [ ] **Step 7: Gate + stage**

Run: `vendor/bin/grumphp run` (this also runs `doctrine:schema:validate` and the full suite — catches any stray reference to the removed method/route).
Then `git add -A` (controllers, repository, tests).
Proposed commit: `117 - bib de-index: non-destructive suppress moved to PhotoTagController; drop deleteBibForEvent`

---

### Task 4: Add the undo (re-index) action

**Files:**
- Modify: `src/Audit/AuditAction.php` — add `EventBibReindex`.
- Modify: `src/Controller/Admin/PhotoTagController.php` — add `reindexBib` action.
- Test: `tests/Functional/Admin/BibSuppressionActionTest.php`

**Interfaces:**
- Consumes: `BibSuppressionRepository::isSuppressed`; a suppression row is removed via the EntityManager.
- Produces: route `admin_bib_reindex` (POST `/admin/events/{id}/bib-reindex`, CSRF `reindex_bib_<id>`) deletes the `(event, bibNumber)` suppression and redirects to `admin_photo_tags`.

- [ ] **Step 1: Write the failing functional test**

Add to `tests/Functional/Admin/BibSuppressionActionTest.php`:

```php
public function testReindexBibRemovesSuppression(): void
{
    [$user, $event] = $this->makeOrganizerWithEvent();
    $this->em->persist(new BibSuppression($event, '1423'));
    $this->em->flush();

    $this->client->loginUser($user);
    $token = $this->primeCsrfToken('reindex_bib_' . $event->getId());

    $this->client->request(Request::METHOD_POST, '/admin/events/' . $event->getId() . '/bib-reindex', [
        'bibNumber' => '1423',
        '_token'    => $token,
    ]);

    self::assertResponseRedirects();

    /** @var BibSuppressionRepository $repo */
    $repo = self::getContainer()->get(BibSuppressionRepository::class);
    $this->assertFalse($repo->isSuppressed($event, '1423'));
}

public function testReindexBibRequiresCsrf(): void
{
    [$user, $event] = $this->makeOrganizerWithEvent();
    $this->client->loginUser($user);

    $this->client->request(Request::METHOD_POST, '/admin/events/' . $event->getId() . '/bib-reindex', [
        'bibNumber' => '1423',
        '_token'    => 'wrong',
    ]);

    self::assertResponseStatusCodeSame(403);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testReindexBibRemovesSuppression`
Expected: FAIL — route `admin_bib_reindex` does not exist (404).

- [ ] **Step 3: Add the audit action**

In `src/Audit/AuditAction.php`, add after the `EventBibSuppress` case:

```php
    case EventBibReindex = 'event.bib_reindex';
```

- [ ] **Step 4: Add the `reindexBib` action to `PhotoTagController`**

```php
    #[Route(
        '/admin/events/{id}/bib-reindex',
        name: 'admin_bib_reindex',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[Audited(AuditAction::EventBibReindex, targetParam: 'id', targetType: 'Event')]
    public function reindexBib(Event $event, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);
        $this->assertCsrf($request, 'reindex_bib_' . $event->getId());

        $bibNumber = trim((string) $request->request->get('bibNumber'));
        if ($bibNumber !== '') {
            $existing = $this->bibSuppressions->findOneBy(['event' => $event, 'bibNumber' => $bibNumber]);
            if ($existing !== null) {
                $this->em->remove($existing);
                $this->em->flush();
                $this->audit->set('reindexed_bib', $bibNumber);
            }
        }

        $this->addFlash('success', sprintf('Bib %s is indexed again.', $bibNumber));

        return $this->redirectToRoute('admin_photo_tags', ['id' => $event->getId()]);
    }
```

(`findOneBy` is inherited from `ServiceEntityRepository` on `BibSuppressionRepository` — no new repo method needed.)

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Functional/Admin/BibSuppressionActionTest.php`
Expected: PASS.

- [ ] **Step 6: Gate + stage**

Run: `vendor/bin/grumphp run`
Then `git add src/Audit/AuditAction.php src/Controller/Admin/PhotoTagController.php tests/Functional/Admin/BibSuppressionActionTest.php`.
Proposed commit: `117 - bib de-index: add reversible re-index (undo) action`

---

### Task 5: Tags page management UI; remove the grid form

The Tags page becomes the management home: each indexed bib chip gains a **De-index** button, a **De-indexed** list shows every suppressed bib with an **Undo** button, and a free-text **De-index a bib number** field is added. The old form is removed from the photo grid.

**Files:**
- Modify: `src/Controller/Admin/PhotoTagController.php` — `overview()` partitions bibs and passes a de-indexed list.
- Modify: `templates/admin/event/photo_tags.html.twig` — de-index button per bib chip; de-indexed section + free-text field.
- Modify: `templates/admin/event/photos_grid.html.twig` — remove the de-index form (lines 70-82).
- Test: `tests/Functional/Admin/BibSuppressionActionTest.php`

**Interfaces:**
- Consumes: `PhotoAttributeRepository::aggregateForEvent(Event): list<array{type,value,count}>`; `BibSuppressionRepository::suppressedBibNumbers(Event): list<string>`; `Event::isBibIndexingEnabled()`.
- Produces: template vars `groups` (each now carries `type`), `deindexedBibs` (`list<array{value:string,count:int}>`), `event`.

- [ ] **Step 1: Write the failing functional test**

Add to `tests/Functional/Admin/BibSuppressionActionTest.php`:

```php
public function testTagsPageShowsDeindexedBibWithUndoAndHidesItFromIndexed(): void
{
    [$user, $event] = $this->makeOrganizerWithEvent();

    $photo = new Photo($event, str_pad('t1', 64, '0'), 'p.jpg', 1000);
    $this->em->persist($photo);
    $this->em->flush();
    $this->em->persist(new PhotoAttribute($photo, PhotoAttributeType::Bib, '1423', 0.99));
    $this->em->persist(new BibSuppression($event, '1423'));
    $this->em->flush();

    $this->client->loginUser($user);
    $crawler = $this->client->request(Request::METHOD_GET, '/admin/events/' . $event->getId() . '/tags');

    self::assertResponseIsSuccessful();
    // A de-indexed bib appears in its own list with a re-index form, not as an indexed chip.
    self::assertGreaterThan(
        0,
        $crawler->filter('form[action$="/bib-reindex"]')->count(),
        'expected an undo (re-index) form for the de-indexed bib',
    );
    self::assertSelectorTextContains('[data-role="deindexed-bib"]', '1423');
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testTagsPageShowsDeindexedBibWithUndoAndHidesItFromIndexed`
Expected: FAIL — no `bib-reindex` form / no `deindexed-bib` element on the page yet.

- [ ] **Step 3: Partition bibs in the controller**

In `src/Controller/Admin/PhotoTagController.php`, replace the body of `overview()` (keep the `denyAccessUnlessGranted` line first):

```php
    public function overview(Event $event): Response
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);

        $suppressed    = $this->bibSuppressions->suppressedBibNumbers($event);
        $suppressedSet = array_flip($suppressed);

        $byType = [];
        foreach ($this->attributes->aggregateForEvent($event) as $row) {
            $byType[$row['type']][] = ['value' => $row['value'], 'count' => $row['count']];
        }

        // Photo counts for de-indexed bib chips (rows still exist under the overlay model).
        $bibCounts = [];
        foreach ($byType['bib'] ?? [] as $item) {
            $bibCounts[$item['value']] = $item['count'];
        }

        $groups = [];
        foreach (self::GROUPS as $type => $meta) {
            $items = $byType[$type] ?? [];
            if ($type === 'bib') {
                $items = array_values(array_filter(
                    $items,
                    static fn (array $i): bool => !isset($suppressedSet[$i['value']]),
                ));
            }

            $groups[] = [
                'type'  => $type,
                'label' => $meta['label'],
                'param' => $meta['param'],
                'multi' => $meta['multi'],
                'items' => $items,
            ];
        }

        // De-indexed list is driven by the suppression table so preemptive
        // (zero-count) de-indexes still appear.
        $deindexedBibs = [];
        foreach ($suppressed as $bib) {
            $deindexedBibs[] = ['value' => $bib, 'count' => $bibCounts[$bib] ?? 0];
        }

        return $this->render('admin/event/photo_tags.html.twig', [
            'event'         => $event,
            'groups'        => $groups,
            'deindexedBibs' => $deindexedBibs,
        ]);
    }
```

Update the `GROUPS` PHPDoc to include `type` is not needed (type is the array key). No other change.

- [ ] **Step 4: Update the Tags template**

In `templates/admin/event/photo_tags.html.twig`, replace the chip loop inside each group card (the `{% for item in group.items %}` block) so bib chips get a de-index button, and add the de-indexed section + free-text field to the bib card. Replace the `<section>…</section>` inside `{% for group in groups %}` with:

```twig
        {% for group in groups %}
            <section class="card bg-base-100 shadow-sm">
                <div class="card-body">
                    <h2 class="card-title text-base">{{ group.label }}</h2>
                    {% if group.items is empty %}
                        <p class="text-sm text-base-content/60">None yet.</p>
                    {% else %}
                        <div class="flex flex-wrap gap-2">
                            {% for item in group.items %}
                                <span class="inline-flex items-center gap-1">
                                    {% if group.param %}
                                        <a data-role="tag-chip"
                                           href="{{ path('public_event_photos', {
                                               slug: event.slug,
                                               (group.param): group.multi ? [item.value] : item.value,
                                           }) }}"
                                           target="_blank" rel="noopener"
                                           class="badge badge-outline gap-1 hover:badge-primary">
                                            {{ item.value }}
                                            <span class="opacity-60">({{ item.count }})</span>
                                        </a>
                                    {% else %}
                                        <span data-role="tag-chip-plain" class="badge badge-outline gap-1">
                                            {{ item.value }}
                                            <span class="opacity-60">({{ item.count }})</span>
                                        </span>
                                    {% endif %}
                                    {% if group.type == 'bib' %}
                                        <form method="post"
                                              action="{{ path('admin_bib_suppress', {id: event.id}) }}"
                                              class="inline">
                                            <input type="hidden" name="bibNumber" value="{{ item.value }}">
                                            <input type="hidden" name="_token"
                                                   value="{{ csrf_token('suppress_bib_' ~ event.id) }}">
                                            <button type="submit"
                                                    data-role="deindex-bib"
                                                    title="De-index bib {{ item.value }}"
                                                    class="btn btn-ghost btn-xs">De-index</button>
                                        </form>
                                    {% endif %}
                                </span>
                            {% endfor %}
                        </div>
                    {% endif %}

                    {% if group.type == 'bib' and event.bibIndexingEnabled %}
                        <div class="mt-4 border-t border-base-300 pt-4">
                            <form method="post"
                                  action="{{ path('admin_bib_suppress', {id: event.id}) }}"
                                  class="flex items-end gap-2">
                                <label class="form-control">
                                    <span class="label-text">De-index a bib number</span>
                                    <input type="text" name="bibNumber"
                                           class="input input-bordered input-sm" maxlength="64" required>
                                </label>
                                <input type="hidden" name="_token"
                                       value="{{ csrf_token('suppress_bib_' ~ event.id) }}">
                                <button type="submit" class="btn btn-sm">De-index</button>
                            </form>

                            <h3 class="mt-4 text-sm font-semibold">De-indexed</h3>
                            {% if deindexedBibs is empty %}
                                <p class="text-sm text-base-content/60">Nothing de-indexed.</p>
                            {% else %}
                                <div class="mt-2 flex flex-wrap gap-2">
                                    {% for bib in deindexedBibs %}
                                        <span data-role="deindexed-bib"
                                              class="inline-flex items-center gap-1">
                                            <span class="badge badge-ghost gap-1">
                                                {{ bib.value }}
                                                <span class="opacity-60">({{ bib.count }})</span>
                                            </span>
                                            <form method="post"
                                                  action="{{ path('admin_bib_reindex', {id: event.id}) }}"
                                                  class="inline">
                                                <input type="hidden" name="bibNumber" value="{{ bib.value }}">
                                                <input type="hidden" name="_token"
                                                       value="{{ csrf_token('reindex_bib_' ~ event.id) }}">
                                                <button type="submit" class="btn btn-ghost btn-xs">Undo</button>
                                            </form>
                                        </span>
                                    {% endfor %}
                                </div>
                            {% endif %}
                        </div>
                    {% endif %}
                </div>
            </section>
        {% endfor %}
```

Update the intro `<p>` copy (lines 23-27) to mention management, e.g. append: "De-index a bib to hide it from visitors; undo it any time from the De-indexed list below."

- [ ] **Step 5: Remove the de-index form from the photo grid**

In `templates/admin/event/photos_grid.html.twig`, delete the whole block at lines 70-82 (`{% if event.bibIndexingEnabled %}` … `{% endif %}` containing the `admin_bib_suppress` form).

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Functional/Admin/BibSuppressionActionTest.php`
Expected: PASS.

- [ ] **Step 7: Gate + verify in the app**

Run: `vendor/bin/grumphp run`
Then drive it: with the stack up (`docker compose up -d`), log in as an organizer, open an event's **Tags** page, de-index a bib from a chip and via the free-text field, confirm it moves to the **De-indexed** list, click **Undo**, confirm it returns to the indexed chips; confirm the photo grid no longer shows a de-index form. (Use the `run`/`verify` skills if helpful.)
Then `git add templates/admin/event/photo_tags.html.twig templates/admin/event/photos_grid.html.twig src/Controller/Admin/PhotoTagController.php tests/Functional/Admin/BibSuppressionActionTest.php`.
Proposed commit: `117 - bib de-index: manage + undo from the Tags page; remove grid form`

---

## Self-Review

**Spec coverage:**
- Core pivot (reversible overlay, no schema change) → Tasks 1-3. ✓
- Write path always persists → Task 2. ✓
- Public search excludes at query time → Task 1. ✓
- `deleteBibForEvent` removed → Task 3. ✓
- Routes moved to `PhotoTagController`, `suppress` non-destructive, redirect to Tags → Task 3. ✓
- `admin_bib_reindex` + `EventBibReindex` audit → Task 4. ✓
- Tags page: indexed chips w/ de-index, de-indexed list w/ undo, free-text field → Task 5. ✓
- Grid form removed → Task 5. ✓
- Tests: extraction flip, search exclusion, functional suppress/reindex/render, drop `deleteBibForEvent` coverage → Tasks 1-5. ✓
- Pre-existing-suppression note → documentation only (spec), no task needed. ✓

**Placeholder scan:** none — all steps carry concrete code/commands.

**Type consistency:** `admin_bib_suppress` / `admin_bib_reindex` route names, CSRF ids `suppress_bib_<id>` / `reindex_bib_<id>`, `AuditAction::EventBibReindex`, template vars `groups`/`deindexedBibs`/`event`, and `deindexedBibs` item shape `{value, count}` are consistent across Tasks 3-5. `suppressedBibNumbers(): list<string>` and `isSuppressed(): bool` match the existing repository. ✓
