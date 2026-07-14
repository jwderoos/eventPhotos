# Keep Originals Toggle (#110) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a per-event "Keep originals" toggle (editable only at 0 photos) that makes the ingest pipeline retain full-resolution originals; clean up all event storage on delete; carry originals through export/import when retained; and lower the per-photo upload cap to 10 MB.

**Architecture:** A new `Event.retainOriginals` boolean gates `ProcessPhotoHandler`'s post-ingest original delete. The admin form uses Symfony's `disabled` field option (which preserves the model value and ignores tampered POST) to lock the toggle once any photo exists. Event delete gains best-effort `deleteDirectory` cleanup across originals/thumbs/previews (mirroring `PhotoController::deleteAll`). The #101 archive manifest gains a `retainOriginals` flag and, when set, carries `.original.jpg` entries that the importer restores.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3, Flysystem, PHPUnit 13. Design spec: `docs/superpowers/specs/2026-07-14-110-keep-originals-design.md`.

## Global Constraints

- **Branch:** all work on `feature/110-keep-originals` (GrumPHP blacklists commits to `main`/`develop`/`master`; branch must match `^(feature|hotfix|bugfix|release)/\d+-`). Create it before Task 1.
- **Commits:** per `CLAUDE.md`, **Claude does not run `git commit`**. Each "Commit" step means: stage the listed files (`git add ...`) and **propose the exact one-line message to the user**; the user commits. Every message must contain the issue number — start it with `110 - `.
- **Migrations:** never hand-write. Generate via `bin/console doctrine:migrations:diff`; edit only `getDescription()`.
- **Quality gates (must pass before each commit is proposed):** `vendor/bin/phpunit` (relevant tests), `vendor/bin/phpstan analyse` (level 10), `vendor/bin/phpcs` (PSR-12), `vendor/bin/rector process --dry-run`, `phpmnd` (no magic numbers in `src/` — use named constants), and `bin/console doctrine:schema:validate`. Run `vendor/bin/grumphp run` before the final task's commit.
- **PHP host commands** run on the host (PHP 8.5 via Homebrew). Restart the worker (`docker compose restart worker`) only when manually exercising the pipeline; tests invoke the handler directly.
- **Test DB:** ensure it exists — `bin/console doctrine:database:create --env=test --if-not-exists` then migrate the test DB after Task 1.

---

### Task 1: `Event.retainOriginals` entity field + migration

**Files:**
- Modify: `src/Entity/Event.php`
- Test: `tests/Unit/Entity/EventTest.php`
- Migration: `migrations/VersionYYYYMMDDHHMMSS.php` (generated)

**Interfaces:**
- Produces: `Event::isRetainOriginals(): bool`, `Event::setRetainOriginals(bool): void`, DB column `events.retain_originals BOOLEAN NOT NULL DEFAULT false`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Entity/EventTest.php` (inside the class):

```php
public function testRetainOriginalsDefaultsToFalse(): void
{
    $event = new Event(
        'slug',
        'Name',
        new DateTimeImmutable('2026-06-10 10:00'),
        new DateTimeImmutable('2026-06-10 12:00'),
        $this->makeOwner(),
    );

    $this->assertFalse($event->isRetainOriginals());
}

public function testRetainOriginalsCanBeToggled(): void
{
    $event = new Event(
        'slug',
        'Name',
        new DateTimeImmutable('2026-06-10 10:00'),
        new DateTimeImmutable('2026-06-10 12:00'),
        $this->makeOwner(),
    );

    $event->setRetainOriginals(true);
    $this->assertTrue($event->isRetainOriginals());

    $event->setRetainOriginals(false);
    $this->assertFalse($event->isRetainOriginals());
}
```

If `EventTest` has no `makeOwner()` helper, check how it constructs a `User` elsewhere in the file and inline the same construction (typically `new User('o@example.test', 'O')`). Reuse the file's existing owner-creation idiom rather than adding a helper if one isn't already present.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testRetainOriginals tests/Unit/Entity/EventTest.php`
Expected: FAIL — `Error: Call to undefined method App\Entity\Event::isRetainOriginals()`.

- [ ] **Step 3: Add the field and accessors**

In `src/Entity/Event.php`, add the property alongside the other scalar columns (e.g. after `$notificationsEnabled` around line 67):

```php
#[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
private bool $retainOriginals = false;
```

Add the accessors near the other boolean accessors (e.g. after `areNotificationsEnabled()`):

```php
public function isRetainOriginals(): bool
{
    return $this->retainOriginals;
}

public function setRetainOriginals(bool $retainOriginals): void
{
    $this->retainOriginals = $retainOriginals;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter testRetainOriginals tests/Unit/Entity/EventTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Generate the migration**

Run: `bin/console doctrine:migrations:diff`
Open the generated `migrations/Version*.php`. Confirm `up()` contains exactly one statement adding the column, e.g.:

```sql
ALTER TABLE events ADD retain_originals BOOLEAN DEFAULT false NOT NULL
```

and `down()` drops it. If `getDescription()` is empty, set it to `'Add retain_originals flag to events (#110).'`. Do **not** hand-edit the SQL.

- [ ] **Step 6: Apply migration to dev + test DB and validate schema**

Run:
```bash
bin/console doctrine:migrations:migrate --no-interaction
bin/console doctrine:database:create --env=test --if-not-exists
bin/console doctrine:migrations:migrate --no-interaction --env=test
bin/console doctrine:schema:validate
```
Expected: schema validate reports "The mapping files are correct" and "The database schema is in sync with the mapping files."

- [ ] **Step 7: Commit**

```bash
git add src/Entity/Event.php tests/Unit/Entity/EventTest.php migrations/
```
Propose message: `110 - add retainOriginals flag to Event entity + migration`

---

### Task 2: `PhotoRepository::countForEvent`

**Files:**
- Modify: `src/Repository/PhotoRepository.php`
- Test: `tests/Integration/Repository/PhotoRepositoryTest.php` (create if absent)

**Interfaces:**
- Produces: `PhotoRepository::countForEvent(Event $event): int` — count of photos of **any** status for the event.

- [ ] **Step 1: Write the failing test**

If `tests/Integration/Repository/PhotoRepositoryTest.php` does not exist, create it:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\User;
use App\Repository\PhotoRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PhotoRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private PhotoRepository $photos;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var PhotoRepository $photos */
        $photos = $c->get(PhotoRepository::class);
        $this->em     = $em;
        $this->photos = $photos;
    }

    public function testCountForEventCountsAllStatuses(): void
    {
        $owner = new User('count@example.test', 'C');
        $owner->setPassword('x');
        $this->em->persist($owner);

        $event = new Event(
            'count-demo',
            'Count',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $this->em->persist($event);
        $this->em->flush();

        $this->assertSame(0, $this->photos->countForEvent($event));

        $pending = new Photo($event, str_pad('a', 64, '0'), 'a.jpg', 100);
        $ready   = new Photo($event, str_pad('b', 64, '0'), 'b.jpg', 100);
        $ready->markReady(new DateTimeImmutable('2026-06-10 11:00'), 100, 100, 50);
        $this->em->persist($pending);
        $this->em->persist($ready);
        $this->em->flush();

        $this->assertSame(2, $this->photos->countForEvent($event));
    }
}
```

This test relies on `dama/doctrine-test-bundle` transactional rollback (already configured); no manual cleanup needed.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Repository/PhotoRepositoryTest.php`
Expected: FAIL — `Error: Call to undefined method App\Repository\PhotoRepository::countForEvent()`.

- [ ] **Step 3: Implement the method**

Add to `src/Repository/PhotoRepository.php` (near `deleteAllForEvent`):

```php
public function countForEvent(Event $event): int
{
    return (int) $this->createQueryBuilder('p')
        ->select('COUNT(p.id)')
        ->andWhere('p.event = :event')
        ->setParameter('event', $event)
        ->getQuery()
        ->getSingleScalarResult();
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Repository/PhotoRepositoryTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Repository/PhotoRepository.php tests/Integration/Repository/PhotoRepositoryTest.php
```
Propose message: `110 - add PhotoRepository::countForEvent (any-status count) for the retain lock`

---

### Task 3: `ProcessPhotoHandler` retains original when flag is on

**Files:**
- Modify: `src/MessageHandler/ProcessPhotoHandler.php`
- Test: `tests/Integration/MessageHandler/ProcessPhotoHandlerTest.php`

**Interfaces:**
- Consumes: `Event::isRetainOriginals()` (Task 1).
- Behaviour: when `event.retainOriginals` is true, the original at `event-<id>/<photoId>.jpg` survives on **both** the success (`markReady`) and rejection (`markFailed`) paths. When false, unchanged (deleted on both paths).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Integration/MessageHandler/ProcessPhotoHandlerTest.php`:

```php
public function testRetainKeepsOriginalOnSuccess(): void
{
    $this->event->setRetainOriginals(true);
    $this->em->flush();

    $photo = $this->seedPending('with-datetime-original.jpg', 'ee');

    ($this->handler)(new ProcessPhoto($photo->getId() ?? 0));
    $this->em->refresh($photo);

    $this->assertSame(PhotoStatus::Ready, $photo->getStatus());
    $path = sprintf('event-%d/%d.jpg', $this->event->getId(), $photo->getId());
    $this->assertTrue(
        $this->originals->fileExists($path),
        'Original must be retained after successful ingest when retainOriginals is on.',
    );
    $this->assertTrue($this->thumbs->fileExists($path));
    $this->assertTrue($this->previews->fileExists($path));
}

public function testRetainKeepsOriginalOnRejection(): void
{
    $this->event->setRetainOriginals(true);
    $this->em->flush();

    $photo = $this->seedPending('no-exif.jpg', 'ff');

    ($this->handler)(new ProcessPhoto($photo->getId() ?? 0));
    $this->em->refresh($photo);

    $this->assertSame(PhotoStatus::Failed, $photo->getStatus());
    $path = sprintf('event-%d/%d.jpg', $this->event->getId(), $photo->getId());
    $this->assertTrue(
        $this->originals->fileExists($path),
        'Original must be retained after domain rejection when retainOriginals is on.',
    );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter testRetainKeepsOriginal tests/Integration/MessageHandler/ProcessPhotoHandlerTest.php`
Expected: FAIL — original does not exist (handler still deletes it unconditionally).

- [ ] **Step 3: Gate the delete behind the flag**

In `src/MessageHandler/ProcessPhotoHandler.php`, first add the `Event` import if missing:

```php
use App\Entity\Event;
```

Replace the two `$this->deleteOriginalQuietly($path, (int) $photo->getId());` calls in `__invoke()` (success branch and `catch (PhotoRejected)` branch) with:

```php
$this->maybeDeleteOriginal($event, $path, (int) $photo->getId());
```

Add this private method next to `deleteOriginalQuietly()`:

```php
/**
 * Retain-aware delete. When the event opts to keep originals (#110), the
 * original survives at photo_originals_storage for re-ingest / paid-original
 * flows; otherwise it is deleted post-ingest as before.
 */
private function maybeDeleteOriginal(Event $event, string $path, int $photoId): void
{
    if ($event->isRetainOriginals()) {
        return;
    }

    $this->deleteOriginalQuietly($path, $photoId);
}
```

Leave `deleteOriginalQuietly()` unchanged.

- [ ] **Step 4: Run the full handler suite to verify pass (new + existing)**

Run: `vendor/bin/phpunit tests/Integration/MessageHandler/ProcessPhotoHandlerTest.php`
Expected: PASS — new retain tests pass and the existing `testHappyPath...` / `testRejectsWhenExifMissing` (retain-off delete) tests still pass.

- [ ] **Step 5: Commit**

```bash
git add src/MessageHandler/ProcessPhotoHandler.php tests/Integration/MessageHandler/ProcessPhotoHandlerTest.php
```
Propose message: `110 - ProcessPhotoHandler retains original when event.retainOriginals is on`

---

### Task 4: Event delete removes all event storage (originals + thumbs + previews)

**Files:**
- Modify: `src/Controller/Admin/EventController.php`
- Test: `tests/Functional/Admin/EventDeleteTest.php` (create)

**Interfaces:**
- Consumes: `photo_originals_storage`, `photo_thumbs_storage`, `photo_previews_storage` Flysystem services.
- Behaviour: after `em->remove($event)`, `deleteDirectory('event-<id>')` runs best-effort on all three storages.

- [ ] **Step 1: Write the failing test**

Create `tests/Functional/Admin/EventDeleteTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\User;
use App\Repository\EventRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

final class EventDeleteTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private FilesystemOperator $originals;

    private FilesystemOperator $thumbs;

    private FilesystemOperator $previews;

    private Event $event;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $c = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var FilesystemOperator $originals */
        $originals = $c->get('photo_originals_storage');
        /** @var FilesystemOperator $thumbs */
        $thumbs = $c->get('photo_thumbs_storage');
        /** @var FilesystemOperator $previews */
        $previews = $c->get('photo_previews_storage');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);

        $this->em        = $em;
        $this->originals = $originals;
        $this->thumbs    = $thumbs;
        $this->previews  = $previews;

        $owner = new User('del-owner@example.test', 'Owner');
        $owner->setPassword($hasher->hashPassword($owner, 'secret'));
        $owner->addRole('ROLE_ORGANIZER');
        $this->em->persist($owner);

        $this->event = new Event(
            'delete-demo',
            'Delete Demo',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $owner,
        );
        $this->event->setRetainOriginals(true);
        $this->em->persist($this->event);
        $this->em->flush();

        $this->client->loginUser($owner);
    }

    public function testDeletingEventRemovesAllStorageDirectories(): void
    {
        $photo = new Photo($this->event, str_pad('a', 64, '0'), 'a.jpg', 100);
        $this->em->persist($photo);
        $this->em->flush();

        $eventId = (int) $this->event->getId();
        $path    = sprintf('event-%d/%d.jpg', $eventId, (int) $photo->getId());
        $this->originals->write($path, "\xFF\xD8ORIGINAL");
        $this->thumbs->write($path, "\xFF\xD8THUMB");
        $this->previews->write($path, "\xFF\xD8PREVIEW");

        $token = $this->primeCsrfToken('delete_event_' . $eventId);
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/delete', $eventId),
            ['_token' => $token],
        );

        self::assertResponseRedirects('/admin/events');

        /** @var EventRepository $events */
        $events = self::getContainer()->get(EventRepository::class);
        $this->assertNull($events->find($eventId), 'Event row must be gone.');

        $dir = sprintf('event-%d', $eventId);
        $this->assertFalse($this->originals->directoryExists($dir), 'Originals dir must be removed.');
        $this->assertFalse($this->thumbs->directoryExists($dir), 'Thumbs dir must be removed.');
        $this->assertFalse($this->previews->directoryExists($dir), 'Previews dir must be removed.');
    }

    private function primeCsrfToken(string $tokenId): string
    {
        $this->client->request(Request::METHOD_GET, '/admin/events');

        $session = $this->client->getRequest()->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }

        $token = bin2hex(random_bytes(16));
        $session->set(SessionTokenStorage::SESSION_NAMESPACE . '/' . $tokenId, $token);
        $session->save();

        return $token;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Admin/EventDeleteTest.php`
Expected: FAIL — directories still exist after delete (no cleanup wired in yet). One or more `directoryExists` assertions fail.

- [ ] **Step 3: Inject storages and delete directories on event delete**

In `src/Controller/Admin/EventController.php`:

Add the import if missing:
```php
use League\Flysystem\FilesystemException;
```

Add three constructor-promoted deps (next to the existing `$eventLogosStorage`):
```php
#[Autowire(service: 'photo_originals_storage')]
private readonly FilesystemOperator $photoOriginals,
#[Autowire(service: 'photo_thumbs_storage')]
private readonly FilesystemOperator $photoThumbs,
#[Autowire(service: 'photo_previews_storage')]
private readonly FilesystemOperator $photoPreviews,
```

In `delete()`, capture the id before removal and clean storage after the flush. The method becomes:

```php
public function delete(Event $event, Request $request): RedirectResponse
{
    $this->denyAccessUnlessGranted(EventVoter::DELETE, $event);

    $token = $request->request->get('_token');

    if (!is_string($token) || !$this->isCsrfTokenValid('delete_event_' . $event->getId(), $token)) {
        throw $this->createAccessDeniedException('Invalid CSRF token.');
    }

    $eventId = (int) $event->getId();

    // Snapshot key fields BEFORE the row is gone (terminate runs after the delete is flushed).
    $this->audit->snapshot(['name' => $event->getName(), 'slug' => $event->getSlug()]);
    $this->audit->targetLabel($event->getName() . ' (' . $event->getSlug() . ')');

    $this->em->remove($event);
    $this->em->flush();

    $dir = sprintf('event-%d', $eventId);
    foreach ([$this->photoOriginals, $this->photoThumbs, $this->photoPreviews] as $fs) {
        try {
            $fs->deleteDirectory($dir);
        } catch (FilesystemException) {
            // Best-effort — event may have had no photos / no derivatives.
        }
    }

    $this->addFlash('success', 'Event deleted.');

    return $this->redirectToRoute('admin_event_index');
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Admin/EventDeleteTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/Admin/EventController.php tests/Functional/Admin/EventDeleteTest.php
```
Propose message: `110 - remove all event storage (originals/thumbs/previews) on event delete`

---

### Task 5: Regression test — single-photo delete removes the original

**Files:**
- Test: `tests/Functional/Admin/PhotoModerationTest.php` (add a test) — no production code change.

**Interfaces:**
- Consumes: existing `PhotoController::delete` (already deletes the original path). This task only locks the behaviour with a test.

- [ ] **Step 1: Confirm no production change is needed**

Re-read `src/Controller/Admin/PhotoController.php:238-245`: it already loops originals/thumbs/previews and `delete($path)`. No edit required.

- [ ] **Step 2: Write the regression test**

Add to `tests/Functional/Admin/PhotoModerationTest.php` (it already has `primeCsrfToken`, `$this->originals`, and an `$this->event`). Model it on the existing single-photo delete test in that file; assert the original is removed:

```php
public function testDeletingPhotoRemovesRetainedOriginal(): void
{
    $this->event->setRetainOriginals(true);
    $this->em->flush();

    $photo = new Photo($this->event, str_pad('c', 64, '0'), 'c.jpg', 100);
    $this->em->persist($photo);
    $this->em->flush();

    $eventId = (int) $this->event->getId();
    $photoId = (int) $photo->getId();
    $path    = sprintf('event-%d/%d.jpg', $eventId, $photoId);
    $this->originals->write($path, "\xFF\xD8ORIGINAL");

    $token = $this->primeCsrfToken('delete_photo_' . $photoId);
    $this->client->request(
        Request::METHOD_POST,
        sprintf('/admin/events/%d/photos/%d/delete', $eventId, $photoId),
        ['_token' => $token],
    );

    $this->assertFalse(
        $this->originals->fileExists($path),
        'Retained original must be removed when the photo is deleted.',
    );
}
```

Verify the imports used (`App\Entity\Photo`, `Symfony\Component\HttpFoundation\Request`) exist at the top of the file; add any that are missing. Confirm the property names (`$this->originals`, `$this->event`, `$this->em`, `$this->client`) match those defined in the file's `setUp()` and adjust if the file uses different names.

- [ ] **Step 3: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter testDeletingPhotoRemovesRetainedOriginal tests/Functional/Admin/PhotoModerationTest.php`
Expected: PASS (behaviour already implemented).

- [ ] **Step 4: Commit**

```bash
git add tests/Functional/Admin/PhotoModerationTest.php
```
Propose message: `110 - regression test: single-photo delete removes retained original`

---

### Task 6: Admin form toggle with 0-photos lock

**Files:**
- Modify: `src/Form/EventType.php`
- Modify: `src/Controller/Admin/EventController.php` (pass `lock_retain_originals` in `new()` and `edit()`)
- Modify: `templates/admin/event/form.html.twig`
- Test: `tests/Functional/Admin/EventRetainOriginalsFormTest.php` (create)

**Interfaces:**
- Consumes: `PhotoRepository::countForEvent` (Task 2), `Event::isRetainOriginals`/`setRetainOriginals` (Task 1).
- Produces: form field `event[retainOriginals]`; new `EventType` option `lock_retain_originals` (bool, default `true`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Functional/Admin/EventRetainOriginalsFormTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\User;
use App\Repository\EventRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EventRetainOriginalsFormTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $owner;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $c = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $this->em = $em;

        $this->owner = new User('retain-owner@example.test', 'Owner');
        $this->owner->setPassword($hasher->hashPassword($this->owner, 'secret'));
        $this->owner->addRole('ROLE_ORGANIZER');
        $this->em->persist($this->owner);
        $this->em->flush();

        $this->client->loginUser($this->owner);
    }

    private function makeEvent(string $slug): Event
    {
        $event = new Event(
            $slug,
            'Retain',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $this->owner,
        );
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    public function testToggleEditableWhenNoPhotosAndPersists(): void
    {
        $event = $this->makeEvent('retain-editable');

        $crawler = $this->client->request(
            Request::METHOD_GET,
            sprintf('/admin/events/%d/edit', (int) $event->getId()),
        );
        self::assertResponseIsSuccessful();

        $checkbox = $crawler->filter('#event_retainOriginals');
        $this->assertCount(1, $checkbox, 'retainOriginals checkbox must render.');
        $this->assertNull(
            $checkbox->attr('disabled'),
            'Checkbox must be enabled when the event has no photos.',
        );

        $form = $crawler->selectButton('Save')->form();
        $form['event[retainOriginals]']->tick();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/events');

        /** @var EventRepository $events */
        $events   = self::getContainer()->get(EventRepository::class);
        $reloaded = $events->find((int) $event->getId());
        $this->assertInstanceOf(Event::class, $reloaded);
        $this->assertTrue($reloaded->isRetainOriginals());
    }

    public function testToggleLockedWhenPhotosExist(): void
    {
        $event = $this->makeEvent('retain-locked');
        $photo = new Photo($event, str_pad('a', 64, '0'), 'a.jpg', 100);
        $this->em->persist($photo);
        $this->em->flush();

        $crawler = $this->client->request(
            Request::METHOD_GET,
            sprintf('/admin/events/%d/edit', (int) $event->getId()),
        );
        self::assertResponseIsSuccessful();

        $checkbox = $crawler->filter('#event_retainOriginals');
        $this->assertCount(1, $checkbox);
        $this->assertSame(
            'disabled',
            $checkbox->attr('disabled'),
            'Checkbox must be disabled once any photo exists.',
        );
    }

    public function testTamperedPostCannotFlipLockedToggle(): void
    {
        $event = $this->makeEvent('retain-tamper');
        $photo = new Photo($event, str_pad('b', 64, '0'), 'b.jpg', 100);
        $this->em->persist($photo);
        $this->em->flush();
        $eventId = (int) $event->getId();

        // Submit the edit form with a crafted retainOriginals value while locked.
        $crawler = $this->client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', $eventId));
        $form    = $crawler->selectButton('Save')->form();
        $values  = $form->getPhpValues();
        $values['event']['retainOriginals'] = '1';
        $this->client->request(Request::METHOD_POST, $form->getUri(), $values);

        /** @var EventRepository $events */
        $events   = self::getContainer()->get(EventRepository::class);
        $reloaded = $events->find($eventId);
        $this->assertInstanceOf(Event::class, $reloaded);
        $this->assertFalse(
            $reloaded->isRetainOriginals(),
            'A disabled field must ignore submitted data — the flag stays false.',
        );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Functional/Admin/EventRetainOriginalsFormTest.php`
Expected: FAIL — `#event_retainOriginals` does not render (field not added yet).

- [ ] **Step 3: Add the field + option to `EventType`**

In `src/Form/EventType.php`, add the field after the `notificationsEnabled` block (around line 149), so it renders in the settings group:

```php
$builder->add('retainOriginals', CheckboxType::class, [
    'required' => false,
    'disabled' => $options['lock_retain_originals'],
    'label'    => 'Keep original photos',
    'help'     => 'Retains the full-resolution originals (up to 10 MB each) — uses '
        . 'significantly more storage than the web derivatives. Can only be changed '
        . 'while the event has no photos.',
]);
```

`CheckboxType` is already imported. Update `configureOptions()`:

```php
public function configureOptions(OptionsResolver $resolver): void
{
    $resolver->setDefaults([
        'data_class'             => Event::class,
        'mail_active'            => false,
        'inherited'              => null,
        'lock_retain_originals'  => true,
    ]);
    $resolver->setAllowedTypes('mail_active', 'bool');
    $resolver->setAllowedTypes('inherited', ['null', ResolvedStyle::class]);
    $resolver->setAllowedTypes('lock_retain_originals', 'bool');
}
```

- [ ] **Step 4: Render the field in the template**

In `templates/admin/event/form.html.twig`, add after the `notificationsEnabled` row (line 44):

```twig
                {{ form_row(form.retainOriginals) }}
```

(Placing it explicitly keeps it out of the `form_widget(form)` catch-all and next to the other settings.)

- [ ] **Step 5: Pass the lock option from the controller**

In `src/Controller/Admin/EventController.php`:

In `new()` — the event is unpersisted (no id, no photos), so it is always unlocked. Update the `createForm` call (around line 110):

```php
$form = $this->createForm(EventType::class, $event, [
    'mail_active'           => $this->mailerResolver->isCustomActive($event->getOwner()),
    'inherited'             => $inherited,
    'lock_retain_originals' => false,
]);
```

In `edit()` — lock once any photo exists (around line 202):

```php
$form = $this->createForm(EventType::class, $event, [
    'mail_active'           => $mailActive,
    'inherited'             => $inherited,
    'lock_retain_originals' => $event->getId() !== null && $this->photos->countForEvent($event) > 0,
]);
```

(`$this->photos` is the injected `PhotoRepository`, already present.)

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Functional/Admin/EventRetainOriginalsFormTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Run the broader event-form suite for regressions**

Run: `vendor/bin/phpunit tests/Functional/Admin/EventStyleEditTest.php tests/Functional/Admin/EventWindowFormTest.php`
Expected: PASS (adding a field must not break existing form submissions).

- [ ] **Step 8: Commit**

```bash
git add src/Form/EventType.php src/Controller/Admin/EventController.php templates/admin/event/form.html.twig tests/Functional/Admin/EventRetainOriginalsFormTest.php
```
Propose message: `110 - add Keep-originals toggle to event form, locked once photos exist`

---

### Task 7: Manifest carries `retainOriginals`

**Files:**
- Modify: `src/Service/Event/Archive/ManifestEvent.php`
- Modify: `src/Service/Event/Archive/EventArchiveManifest.php`
- Test: `tests/Unit/Service/Event/Archive/EventArchiveManifestTest.php`

**Interfaces:**
- Produces: `ManifestEvent::$retainOriginals` (bool, last constructor param); manifest JSON key `event.retainOriginals`; `fromJson` defaults it to `false` when absent.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Service/Event/Archive/EventArchiveManifestTest.php`. First inspect the file's existing `manifest()` builder helper (used by `testJsonRoundTrip`) and add `retainOriginals` as its final `ManifestEvent` argument (`true`), then add:

```php
public function testRetainOriginalsSurvivesJsonRoundTrip(): void
{
    $restored = EventArchiveManifest::fromJson($this->manifest()->toJson());

    $this->assertTrue($restored->event->retainOriginals);
}

public function testRetainOriginalsDefaultsFalseWhenAbsent(): void
{
    // A pre-#110 manifest with no retainOriginals key must import as retain-off.
    $json = $this->manifest()->toJson();
    $data = json_decode($json, true);
    unset($data['event']['retainOriginals']);

    $restored = EventArchiveManifest::fromJson(json_encode($data));

    $this->assertFalse($restored->event->retainOriginals);
}
```

If the `manifest()` helper builds `ManifestEvent` positionally, the new final `true` argument is what the first test asserts survives. If the file has no such helper, construct a minimal `EventArchiveManifest` inline mirroring the existing tests.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Service/Event/Archive/EventArchiveManifestTest.php`
Expected: FAIL — `ManifestEvent` has no `$retainOriginals` (too few / unknown named argument, or property undefined).

- [ ] **Step 3: Add the field to `ManifestEvent`**

In `src/Service/Event/Archive/ManifestEvent.php`, append the constructor param:

```php
public function __construct(
    public string $name,
    public string $slug,
    public ?string $description,
    public string $timezone,
    public string $startsAt,
    public string $endsAt,
    public ?string $publishedAt,
    public bool $notificationsEnabled,
    public ?string $fontColor,
    public ?string $backgroundColor,
    public ?string $buttonColor,
    public ?bool $glowEnabled,
    public ?string $logoFilename,
    public bool $retainOriginals = false,
) {
}
```

- [ ] **Step 4: Serialize + parse in `EventArchiveManifest`**

In `src/Service/Event/Archive/EventArchiveManifest.php`:

In `toArray()`, add to the `event` array (e.g. after `notificationsEnabled`):
```php
'retainOriginals'      => $this->event->retainOriginals,
```

In `fromJson()`, extend the `new ManifestEvent(...)` call with a final argument (after the `logo`/`glowEnabled` args):
```php
(bool) ($event['retainOriginals'] ?? false),
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Service/Event/Archive/EventArchiveManifestTest.php`
Expected: PASS (including the pre-existing `testJsonRoundTrip`).

- [ ] **Step 6: Commit**

```bash
git add src/Service/Event/Archive/ManifestEvent.php src/Service/Event/Archive/EventArchiveManifest.php tests/Unit/Service/Event/Archive/EventArchiveManifestTest.php
```
Propose message: `110 - archive manifest carries retainOriginals (defaults false for old archives)`

---

### Task 8: Exporter writes originals when retained

**Files:**
- Modify: `src/Service/Event/EventArchiveExporter.php`
- Test: `tests/Integration/Service/Event/EventArchiveRoundtripTest.php`

**Interfaces:**
- Consumes: `Event::isRetainOriginals`, `photo_originals_storage`, `ManifestEvent::$retainOriginals` (Task 7).
- Produces: archive entries `photos/<hash>.original.jpg` for each Ready photo when retain is on; manifest `event.retainOriginals` reflects the event flag.

- [ ] **Step 1: Write the failing test**

Add to `tests/Integration/Service/Event/EventArchiveRoundtripTest.php`. First add an `$originals` `FilesystemOperator` property + fetch it in `setUp()` (mirror the existing `$thumbs`/`$previews` wiring, service id `photo_originals_storage`). Then:

```php
public function testExportIncludesOriginalsWhenRetained(): void
{
    $utc   = new DateTimeZone('UTC');
    $owner = $this->makeUser('exp-orig@example.com');

    $event = new Event(
        'export-originals',
        'Export Originals',
        new DateTimeImmutable('2026-03-01 10:00:00', $utc),
        new DateTimeImmutable('2026-03-01 12:00:00', $utc),
        $owner,
    );
    $event->setRetainOriginals(true);
    $this->em->persist($event);
    $this->em->flush();

    $photo = new Photo($event, str_repeat('c', 64), 'IMG_O.jpg', 111);
    $photo->markReady(new DateTimeImmutable('2026-03-01 11:00:00', $utc), 4000, 3000, 200_000);
    $this->em->persist($photo);
    $this->em->flush();

    $path = sprintf('event-%d/%d.jpg', (int) $event->getId(), (int) $photo->getId());
    $this->originals->write($path, "\xFF\xD8ORIGINALBYTES");
    $this->thumbs->write($path, "\xFF\xD8THUMB");
    $this->previews->write($path, "\xFF\xD8PREVIEW");

    $zip = $this->exporter->export($event);

    $za = new \ZipArchive();
    $this->assertTrue($za->open($zip) === true);
    $original = $za->getFromName('photos/' . str_repeat('c', 64) . '.original.jpg');
    $manifest = $za->getFromName('manifest.json');
    $za->close();
    @unlink($zip);

    $this->assertSame("\xFF\xD8ORIGINALBYTES", $original);
    $this->assertStringContainsString('"retainOriginals": true', (string) $manifest);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testExportIncludesOriginalsWhenRetained tests/Integration/Service/Event/EventArchiveRoundtripTest.php`
Expected: FAIL — `.original.jpg` entry is `false` (not written) and manifest lacks `retainOriginals: true`.

- [ ] **Step 3: Inject originals + write them in the exporter**

In `src/Service/Event/EventArchiveExporter.php`:

Add the constructor dep (next to `$thumbs`/`$previews`):
```php
#[Autowire(service: 'photo_originals_storage')]
private FilesystemOperator $originals,
```

Inside `foreach ($ready as $photo)`, after the thumb/preview `addFromString` calls, add:
```php
if ($event->isRetainOriginals()) {
    $zip->addFromString('photos/' . $hash . '.original.jpg', $this->originals->read($path));
}
```

In `buildManifestEvent()`, pass the flag as the final `ManifestEvent` argument:
```php
return new ManifestEvent(
    $event->getName(),
    $event->getSlug(),
    $event->getDescription(),
    $event->getTimezone(),
    self::iso($event->getStartsAt()) ?? '',
    self::iso($event->getEndsAt()) ?? '',
    self::iso($event->getPublishedAt()),
    $event->areNotificationsEnabled(),
    $style->getFontColor(),
    $style->getBackgroundColor(),
    $style->getButtonColor(),
    $style->getGlowEnabled(),
    $logoFilename,
    $event->isRetainOriginals(),
);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter testExportIncludesOriginalsWhenRetained tests/Integration/Service/Event/EventArchiveRoundtripTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Event/EventArchiveExporter.php tests/Integration/Service/Event/EventArchiveRoundtripTest.php
```
Propose message: `110 - export archive carries photo originals when event retains them`

---

### Task 9: Importer restores originals + flag; hard-fails on missing original

**Files:**
- Modify: `src/Service/Event/EventArchiveImporter.php`
- Test: `tests/Integration/Service/Event/EventArchiveRoundtripTest.php`

**Interfaces:**
- Consumes: `photo_originals_storage`, `Event::setRetainOriginals`, `ManifestEvent::$retainOriginals`.
- Behaviour: when the manifest's `retainOriginals` is true, the importer sets the flag on the new event and, for each Ready photo, reads+validates `photos/<hash>.original.jpg` and writes it to `photo_originals_storage`. A missing/invalid original entry throws `InvalidArchiveException` and rolls back (via the existing `$written` cleanup).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Integration/Service/Event/EventArchiveRoundtripTest.php`:

```php
public function testImportRestoresOriginalsAndFlag(): void
{
    $utc   = new DateTimeZone('UTC');
    $owner = $this->makeUser('imp-orig@example.com');

    $event = new Event(
        'import-originals-src',
        'Import Originals',
        new DateTimeImmutable('2026-03-01 10:00:00', $utc),
        new DateTimeImmutable('2026-03-01 12:00:00', $utc),
        $owner,
    );
    $event->setRetainOriginals(true);
    $this->em->persist($event);
    $this->em->flush();

    $photo = new Photo($event, str_repeat('d', 64), 'IMG_D.jpg', 111);
    $photo->markReady(new DateTimeImmutable('2026-03-01 11:00:00', $utc), 4000, 3000, 200_000);
    $this->em->persist($photo);
    $this->em->flush();

    $path = sprintf('event-%d/%d.jpg', (int) $event->getId(), (int) $photo->getId());
    $this->originals->write($path, "\xFF\xD8ORIGINALBYTES");
    $this->thumbs->write($path, "\xFF\xD8THUMB");
    $this->previews->write($path, "\xFF\xD8PREVIEW");

    $zip = $this->exporter->export($event);
    $event->setSlug('import-originals-src-archived');
    $this->em->flush();

    $imported = $this->importer->import($zip, $owner);
    @unlink($zip);

    $this->assertTrue($imported->isRetainOriginals());

    /** @var PhotoRepository $photos */
    $photos      = self::getContainer()->get(PhotoRepository::class);
    $importedPhoto = $photos->findReadyInWindow(
        $imported,
        new DateTimeImmutable('2026-03-01 00:00:00', $utc),
        new DateTimeImmutable('2026-03-02 00:00:00', $utc),
    )[0];
    $importedPath = sprintf('event-%d/%d.jpg', (int) $imported->getId(), (int) $importedPhoto->getId());
    $this->assertSame("\xFF\xD8ORIGINALBYTES", $this->originals->read($importedPath));
}

public function testImportFailsWhenRetainedOriginalMissing(): void
{
    $utc   = new DateTimeZone('UTC');
    $owner = $this->makeUser('imp-missing@example.com');

    // Build an archive that CLAIMS retainOriginals but omits the .original.jpg entry.
    $manifest = [
        'format'  => 'eventphotos.event-export',
        'version' => 1,
        'exportedAt'     => '2026-03-01T10:00:00+00:00',
        'sourceInstance' => '',
        'event' => [
            'name' => 'Broken', 'slug' => 'broken-archive', 'description' => null,
            'timezone' => 'UTC', 'startsAt' => '2026-03-01T10:00:00+00:00',
            'endsAt' => '2026-03-01T12:00:00+00:00', 'publishedAt' => null,
            'notificationsEnabled' => false,
            'style' => ['fontColor' => null, 'backgroundColor' => null, 'buttonColor' => null, 'glowEnabled' => null],
            'logo' => null,
            'retainOriginals' => true,
        ],
        'photos' => [[
            'contentHash' => str_repeat('e', 64), 'originalFilename' => 'x.jpg',
            'byteSize' => 100, 'width' => 10, 'height' => 10,
            'takenAt' => '2026-03-01T11:00:00+00:00', 'derivativeBytes' => 50,
            'createdAt' => '2026-03-01T10:30:00+00:00',
        ]],
        'subscriptions' => [],
        'skippedPhotos' => 0,
    ];

    $zipPath = tempnam(sys_get_temp_dir(), 'evt-broken-');
    $za = new \ZipArchive();
    $za->open($zipPath, \ZipArchive::OVERWRITE);
    $za->addFromString('manifest.json', (string) json_encode($manifest));
    $hash = str_repeat('e', 64);
    $za->addFromString('photos/' . $hash . '.thumb.jpg', "\xFF\xD8THUMB");
    $za->addFromString('photos/' . $hash . '.preview.jpg', "\xFF\xD8PREVIEW");
    // NOTE: no .original.jpg entry.
    $za->close();

    try {
        $this->expectException(InvalidArchiveException::class);
        $this->importer->import($zipPath, $owner);
    } finally {
        @unlink($zipPath);
    }
}
```

Add `use App\Service\Event\Archive\InvalidArchiveException;` to the test file's imports.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter "testImportRestoresOriginalsAndFlag|testImportFailsWhenRetainedOriginalMissing" tests/Integration/Service/Event/EventArchiveRoundtripTest.php`
Expected: FAIL — imported event's flag is false and original not written; missing-original archive imports without throwing.

- [ ] **Step 3: Restore originals + flag in the importer**

In `src/Service/Event/EventArchiveImporter.php`:

Add the constructor dep (next to `$thumbs`/`$previews`):
```php
#[Autowire(service: 'photo_originals_storage')]
private FilesystemOperator $originals,
```

In `reconstitute()`, after the other event setters (e.g. after the `notificationsEnabled` block, before the transaction or right after persist — set it before the photo loop):
```php
$event->setRetainOriginals($me->retainOriginals);
```
(`$me = $manifest->event;` is already in scope.)

Pass the event flag into `reconstitutePhoto`. Change the loop call:
```php
foreach ($manifest->photos as $manifestPhoto) {
    $this->reconstitutePhoto($event, $manifestPhoto, $zip, $written);
}
```
`$event` is already passed. Update `reconstitutePhoto()` to read the flag from the event and write the original. After the existing thumb/preview writes:
```php
if ($event->isRetainOriginals()) {
    $originalBytes = $this->readJpeg($zip, 'photos/' . $mp->contentHash . '.original.jpg');
    $this->originals->write($path, $originalBytes);
    $written[] = [$this->originals, $path];
}
```
`readJpeg` already throws `InvalidArchiveException` on a missing/non-JPEG entry, giving the hard-fail. The existing `catch (Throwable)` block in `reconstitute()` deletes everything tracked in `$written` (now including originals) and rolls back the transaction.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter "testImportRestoresOriginalsAndFlag|testImportFailsWhenRetainedOriginalMissing" tests/Integration/Service/Event/EventArchiveRoundtripTest.php`
Expected: PASS.

- [ ] **Step 5: Run the full archive round-trip suite for regressions**

Run: `vendor/bin/phpunit tests/Integration/Service/Event/EventArchiveRoundtripTest.php tests/Functional/Admin/EventExportTest.php tests/Functional/Admin/EventImportTest.php`
Expected: PASS — existing retain-off round-trip still works (no originals written when flag is off).

- [ ] **Step 6: Commit**

```bash
git add src/Service/Event/EventArchiveImporter.php tests/Integration/Service/Event/EventArchiveRoundtripTest.php
```
Propose message: `110 - import restores retained originals; missing original hard-fails the import`

---

### Task 10: Lower per-photo upload cap 25 MB → 10 MB

**Files:**
- Modify: `src/Controller/Admin/PhotoController.php:35`
- Modify: `assets/controllers/photo_uploader_controller.js:3,22,109`
- Modify: `CLAUDE.md` (ingest pipeline section)
- Test: `tests/Functional/Admin/PhotoUploadTest.php`

**Interfaces:**
- Behaviour: uploads over 10 MB return HTTP 413; the JS pre-check and UI copy read "10 MB".

- [ ] **Step 1: Write the failing test**

Add to `tests/Functional/Admin/PhotoUploadTest.php`:

```php
public function testRejectsOversizedFile(): void
{
    // Build an ~11 MB file that still sniffs as JPEG (real JPEG header + padding),
    // so it passes the MIME check and trips the size check.
    $src   = dirname(__DIR__, 2) . '/fixtures/photos/with-datetime-original.jpg';
    $dst   = sys_get_temp_dir() . '/oversize-' . uniqid() . '.jpg';
    copy($src, $dst);
    $fh = fopen($dst, 'ab');
    ftruncate($fh, 11 * 1024 * 1024);
    fclose($fh);

    $file = new UploadedFile($dst, 'oversize.jpg', 'image/jpeg', null, true);
    $url  = sprintf('/admin/events/%d/photos', (int) $this->event->getId());
    $this->client->request(Request::METHOD_POST, $url, [], ['file' => $file]);

    self::assertResponseStatusCodeSame(413);
}

public function testAcceptsFileAtNewLimit(): void
{
    // The standard fixture is well under 10 MB and must still ingest.
    $file = $this->fixture('with-datetime-original.jpg');
    $url  = sprintf('/admin/events/%d/photos', (int) $this->event->getId());
    $this->client->request(Request::METHOD_POST, $url, [], ['file' => $file]);

    self::assertResponseStatusCodeSame(202);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter "testRejectsOversizedFile|testAcceptsFileAtNewLimit" tests/Functional/Admin/PhotoUploadTest.php`
Expected: `testRejectsOversizedFile` FAILS (11 MB currently under the 25 MB cap → not 413); `testAcceptsFileAtNewLimit` passes.

- [ ] **Step 3: Lower the server-side cap**

In `src/Controller/Admin/PhotoController.php:35`:
```php
private const int MAX_BYTES = 10 * 1024 * 1024;
```

- [ ] **Step 4: Lower the client-side cap + UI copy**

In `assets/controllers/photo_uploader_controller.js`:
- Line 3: `const MAX_BYTES = 10 * 1024 * 1024;`
- Line ~22 (hint): change `JPEG only, up to 25 MB each` → `JPEG only, up to 10 MB each`
- Line ~109 (error): change `'Too large (>25 MB)'` → `'Too large (>10 MB)'`

- [ ] **Step 5: Update the docs**

In `CLAUDE.md`, in the Photo ingest pipeline section, change `validates (JPEG only, ≤25 MB)` → `validates (JPEG only, ≤10 MB)`.

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Functional/Admin/PhotoUploadTest.php`
Expected: PASS (all tests, including the existing happy-path/duplicate/mime tests).

- [ ] **Step 7: Commit**

```bash
git add src/Controller/Admin/PhotoController.php assets/controllers/photo_uploader_controller.js CLAUDE.md tests/Functional/Admin/PhotoUploadTest.php
```
Propose message: `110 - lower per-photo upload cap from 25 MB to 10 MB (server + client + docs)`

---

### Task 11: Full-suite verification + quality gates

**Files:** none (verification only).

- [ ] **Step 1: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: green (no failures, no deprecations/notices/warnings — the suite fails on any).

- [ ] **Step 2: Run all quality gates**

Run: `vendor/bin/grumphp run`
Expected: all tasks pass — `phpstan` (level 10), `phpcs` (PSR-12), `phpmnd`, `phpcpd`, `rector`, `securitychecker_roave`, and `doctrine:schema:validate`.

- [ ] **Step 3: Fix any gate failures**

If phpstan/phpcs/rector flag issues, fix inline and re-run the specific gate. Common: unused imports, missing return types, array shape docblocks on the new test data arrays. Re-run `vendor/bin/grumphp run` until green.

- [ ] **Step 4: Manual smoke (optional but recommended)**

```bash
docker compose up -d
docker compose restart worker
```
Create an event, toggle "Keep original photos" (0 photos → editable), upload a photo, confirm via `docker compose logs -f worker` that ingest succeeds and the original remains under `var/uploads/photos/originals/event-<id>/`. Reload the edit page and confirm the toggle is now disabled. Delete the event and confirm `var/uploads/photos/{originals,thumbs,previews}/event-<id>/` are all gone.

- [ ] **Step 5: Final commit (if fixes were made in Step 3)**

```bash
git add -A
```
Propose message: `110 - satisfy quality gates for keep-originals feature`

---

## Self-Review Notes

- **Spec coverage:** Entity+migration (T1), lock count helper (T2), handler retain (T3), event-delete cleanup all-three-prefixes (T4), single-photo-delete regression (T5), form lock + tamper-proofing (T6), manifest flag + backward compat (T7), export originals (T8), import originals + hard-fail (T9), 10 MB cap server/client/docs (T10), gates (T11). All acceptance criteria mapped.
- **Type consistency:** `isRetainOriginals()`/`setRetainOriginals()` used identically across T1/T3/T4/T6/T8/T9. `countForEvent(Event): int` defined T2, consumed T6. `ManifestEvent::$retainOriginals` (bool, default false) defined T7, consumed T8/T9. Storage service ids (`photo_originals_storage`, etc.) consistent throughout.
- **Ordering:** T1 (entity) precedes everything that reads the flag. T2 precedes T6 (form lock). T7 (manifest) precedes T8/T9 (export/import). T10 is independent (can run any time after branch creation). T11 is last.
