# Re-ingest event images (#112) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an organizer re-ingest an event's `Ready` photos — delete their derivatives and regenerate thumb + preview from the retained original, picking up the event's current preview size/quality settings (#111).

**Architecture:** A new `Photo::resetForReingest()` domain transition (`Ready → Pending`) plus a `reingest` flag on the existing `ProcessPhoto` message. The thin controller resets Ready photos and re-dispatches; the existing worker/handler — when the flag is set — skips the ingest time-window guard and deletes the old derivatives before regenerating. Re-ingest is gated entirely on the event-level `retainOriginals` flag (#110). No new async plumbing — reuses the existing `async` transport and idempotent handler.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3, Symfony Messenger (Doctrine transport; `in-memory://` in test), Flysystem, Twig + Turbo + Tailwind/daisyUI, PHPUnit 13.

## Global Constraints

- Branch: `feature/112-reingest-event-images` (already created; matches `^(feature|hotfix|bugfix|release)/\d+-`).
- Commit messages MUST contain the issue number `#112`.
- PHP attributes only — no annotations. `declare(strict_types=1);` in every PHP file.
- Quality gates (all run by GrumPHP, all must stay green): `phpstan` level 10, `phpcs` PSR-12, `phpmnd` (no magic numbers in `src/`), `phpcpd` (50-line/100-token duplication), `rector`, `doctrine:schema:validate`.
- No new DB columns or migrations — this feature adds no persisted state.
- Storage path for every photo is `event-<eventId>/<photoId>.jpg` on each of the three photo storages.
- Illegal state-machine moves throw `DomainException` — never bypass the transition methods.
- Do NOT add or restore a `retry` route — `tests/Functional/Admin/PhotoModerationTest.php::testRetryRouteIsGone` asserts `/retry` 404s. Re-ingest uses new route names only.

---

### Task 1: `Photo::resetForReingest()` domain transition

Adds the explicit `Ready → Pending` transition the feature needs (the existing `resetForRetry` only allows `Failed → Pending`).

**Files:**
- Modify: `src/Entity/Photo.php` (add method after `resetForRetry()`, around line 186)
- Test: `tests/Unit/Entity/PhotoTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Photo::resetForReingest(): void` — throws `DomainException` unless status is `PhotoStatus::Ready`; on success sets status to `PhotoStatus::Pending` and clears `processingError`. Leaves `takenAt`/`width`/`height`/`derivativeBytes` untouched.

- [ ] **Step 1: Write the failing tests**

Add these three methods to `tests/Unit/Entity/PhotoTest.php` (before the `makePhoto()` helper). They reuse the existing `makePhoto()` helper and imports (`PhotoStatus`, `DomainException`, `DateTimeImmutable`):

```php
    public function testResetForReingestFromReady(): void
    {
        $photo = $this->makePhoto();
        $photo->markReady(new DateTimeImmutable('2026-06-10 12:00:00'), 100, 100, 2048);

        $photo->resetForReingest();

        $this->assertSame(PhotoStatus::Pending, $photo->getStatus());
        $this->assertNull($photo->getProcessingError());
    }

    public function testResetForReingestRejectedFromPending(): void
    {
        $photo = $this->makePhoto();

        $this->expectException(DomainException::class);
        $photo->resetForReingest();
    }

    public function testResetForReingestRejectedFromFailed(): void
    {
        $photo = $this->makePhoto();
        $photo->markFailed('boom');

        $this->expectException(DomainException::class);
        $photo->resetForReingest();
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter testResetForReingest tests/Unit/Entity/PhotoTest.php`
Expected: FAIL — `Error: Call to undefined method App\Entity\Photo::resetForReingest()`.

- [ ] **Step 3: Implement the transition**

In `src/Entity/Photo.php`, add immediately after the `resetForRetry()` method:

```php
    public function resetForReingest(): void
    {
        if ($this->status !== PhotoStatus::Ready) {
            throw new DomainException(sprintf(
                'Photo %d cannot be reset for re-ingest from %s.',
                (int) $this->id,
                $this->status->value,
            ));
        }

        $this->processingError = null;
        $this->status = PhotoStatus::Pending;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter testResetForReingest tests/Unit/Entity/PhotoTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Entity/Photo.php tests/Unit/Entity/PhotoTest.php
git commit -m "112 - add Photo::resetForReingest (Ready -> Pending) transition #112"
```

---

### Task 2: `ProcessPhoto` re-ingest flag + `DerivativeGenerator::delete()`

Adds the message flag (backward-compatible default) and a derivative-deletion method on the generator (which already owns the thumb/preview storages).

**Files:**
- Modify: `src/Message/ProcessPhoto.php`
- Modify: `src/Service/Photo/DerivativeGenerator.php`
- Test: `tests/Integration/Service/Photo/DerivativeGeneratorDeleteTest.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `ProcessPhoto::__construct(int $photoId, bool $reingest = false)` — public promoted properties `$photoId` and `$reingest`.
  - `DerivativeGenerator::delete(string $path): void` — best-effort removal of the thumb and preview objects at `$path` from `photo_thumbs_storage` and `photo_previews_storage`; swallows `FilesystemException` (missing files are fine).

- [ ] **Step 1: Add the message flag** (no separate test — trivial value object, exercised in Tasks 3–4)

Replace the constructor in `src/Message/ProcessPhoto.php` so it reads:

```php
final readonly class ProcessPhoto
{
    public function __construct(public int $photoId, public bool $reingest = false)
    {
    }
}
```

- [ ] **Step 2: Write the failing test for `DerivativeGenerator::delete()`**

Create `tests/Integration/Service/Photo/DerivativeGeneratorDeleteTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Photo;

use App\Service\Photo\DerivativeGenerator;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

final class DerivativeGeneratorDeleteTest extends KernelTestCase
{
    private DerivativeGenerator $generator;

    private FilesystemOperator $thumbs;

    private FilesystemOperator $previews;

    private const string PATH = 'event-99999/1.jpg';

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();

        /** @var DerivativeGenerator $generator */
        $generator = $c->get(DerivativeGenerator::class);
        /** @var FilesystemOperator $thumbs */
        $thumbs = $c->get('photo_thumbs_storage');
        /** @var FilesystemOperator $previews */
        $previews = $c->get('photo_previews_storage');

        $this->generator = $generator;
        $this->thumbs = $thumbs;
        $this->previews = $previews;
    }

    public function testDeleteRemovesThumbAndPreview(): void
    {
        $this->thumbs->write(self::PATH, 'thumb-bytes');
        $this->previews->write(self::PATH, 'preview-bytes');

        $this->generator->delete(self::PATH);

        $this->assertFalse($this->thumbs->fileExists(self::PATH));
        $this->assertFalse($this->previews->fileExists(self::PATH));
    }

    public function testDeleteIsBestEffortWhenFilesAbsent(): void
    {
        $this->expectNotToPerformAssertions();

        // No files written — must not throw.
        $this->generator->delete(self::PATH);
    }

    protected function tearDown(): void
    {
        foreach ([$this->thumbs, $this->previews] as $fs) {
            try {
                $fs->deleteDirectory('event-99999');
            } catch (Throwable) {
            }
        }

        parent::tearDown();
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Service/Photo/DerivativeGeneratorDeleteTest.php`
Expected: FAIL — `Error: Call to undefined method App\Service\Photo\DerivativeGenerator::delete()`.

- [ ] **Step 4: Implement `delete()`**

In `src/Service/Photo/DerivativeGenerator.php`, add a `use League\Flysystem\FilesystemException;` import if not already present, then add this method (the class already injects `$this->thumbs` and `$this->previews` `FilesystemOperator`s — confirm their property names by reading the constructor and match them):

```php
    /**
     * Best-effort removal of the generated derivatives at $path. Used by re-ingest
     * (#112) to clear stale thumb/preview before regenerating. Missing files are fine.
     */
    public function delete(string $path): void
    {
        foreach ([$this->thumbs, $this->previews] as $fs) {
            try {
                $fs->delete($path);
            } catch (FilesystemException) {
                // Missing files are fine — nothing to clear.
            }
        }
    }
```

If the existing thumb/preview properties are named differently (e.g. `$thumbStorage`), use those names instead.

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Service/Photo/DerivativeGeneratorDeleteTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Message/ProcessPhoto.php src/Service/Photo/DerivativeGenerator.php tests/Integration/Service/Photo/DerivativeGeneratorDeleteTest.php
git commit -m "112 - add ProcessPhoto reingest flag and DerivativeGenerator::delete #112"
```

---

### Task 3: `ProcessPhotoHandler` re-ingest handling

When the message carries `reingest: true`, skip the ingest window guard (an already-accepted photo must not be re-rejected) and delete the old derivatives before regenerating.

**Files:**
- Modify: `src/MessageHandler/ProcessPhotoHandler.php:53-74` (the `try` block in `__invoke`)
- Test: `tests/Integration/MessageHandler/ProcessPhotoHandlerTest.php`

**Interfaces:**
- Consumes: `ProcessPhoto::$reingest` (Task 2), `DerivativeGenerator::delete()` (Task 2).
- Produces: no new public surface — behavioural change to `__invoke(ProcessPhoto $message)`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Integration/MessageHandler/ProcessPhotoHandlerTest.php` (it already imports `ProcessPhoto`, `PhotoStatus`, `DateTimeImmutable`, `DateTimeZone` and has `seedPending()`):

```php
    public function testReingestSkipsWindowGuardAndRegenerates(): void
    {
        // Event window is two days after the fixture's EXIF timestamp, so a fresh
        // ingest would window-reject. Re-ingest must NOT re-reject.
        $this->event->setRetainOriginals(true);
        $this->event->setStartsAt(new DateTimeImmutable('2026-06-12 10:00', new DateTimeZone('UTC')));
        $this->event->setEndsAt(new DateTimeImmutable('2026-06-12 14:00', new DateTimeZone('UTC')));
        $this->em->flush();

        // Simulate the controller having reset a Ready photo back to Pending, with
        // its retained original still on disk (seedPending writes the original).
        $photo = $this->seedPending('with-datetime-original.jpg', 'reingest-window');
        $path  = sprintf('event-%d/%d.jpg', $this->event->getId(), $photo->getId());

        // Stale derivatives from the previous ingest.
        $this->thumbs->write($path, 'STALE-THUMB');
        $this->previews->write($path, 'STALE-PREVIEW');

        ($this->handler)(new ProcessPhoto($photo->getId() ?? 0, reingest: true));
        $this->em->refresh($photo);

        $this->assertSame(
            PhotoStatus::Ready,
            $photo->getStatus(),
            'Re-ingest must not window-reject an already-accepted photo.',
        );
        $this->assertTrue($this->thumbs->fileExists($path));
        $this->assertTrue($this->previews->fileExists($path));
        $this->assertNotSame('STALE-THUMB', $this->thumbs->read($path), 'Thumb should be regenerated.');
        $this->assertNotSame('STALE-PREVIEW', $this->previews->read($path), 'Preview should be regenerated.');
        $this->assertSame(
            $this->thumbs->fileSize($path) + $this->previews->fileSize($path),
            $photo->getDerivativeBytes(),
            'derivativeBytes must reflect the freshly generated derivatives.',
        );
        $this->assertTrue(
            $this->originals->fileExists($path),
            'Original must survive re-ingest so the event can be re-ingested again.',
        );
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testReingestSkipsWindowGuardAndRegenerates tests/Integration/MessageHandler/ProcessPhotoHandlerTest.php`
Expected: FAIL — the photo ends `Failed` (window guard still runs), so the `assertSame(PhotoStatus::Ready, ...)` fails.

- [ ] **Step 3: Implement the handler branch**

In `src/MessageHandler/ProcessPhotoHandler.php`, replace the body of the `try {` block (currently lines 54–69) so it reads:

```php
            $tmpFile = $this->stageToTmp($path);
            try {
                $takenAt = $this->exifReader->readTakenAt(
                    $tmpFile,
                    new DateTimeZone($event->getTimezone()),
                );
            } finally {
                @unlink($tmpFile);
            }

            if (!$message->reingest) {
                $this->windowGuard->assertWithinWindow($event, $takenAt);
            } else {
                $this->derivatives->delete($path);
            }

            [$width, $height, $derivativeBytes] = $this->derivatives->generate($path, $event->getPreviewSettings());
            $photo->markReady($takenAt, $width, $height, $derivativeBytes);
            $this->em->flush();
            $this->maybeDeleteOriginal($event, $path, (int) $photo->getId());
```

The `catch (PhotoRejected ...)` block below is unchanged. Note: on re-ingest, `PhotoRejected` can no longer be thrown from the window guard; it remains reachable only from `generate()` if that ever throws it (it does not today), so the catch stays as a safety net.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter testReingestSkipsWindowGuardAndRegenerates tests/Integration/MessageHandler/ProcessPhotoHandlerTest.php`
Expected: PASS.

- [ ] **Step 5: Run the whole handler test to confirm no regression**

Run: `vendor/bin/phpunit tests/Integration/MessageHandler/ProcessPhotoHandlerTest.php`
Expected: PASS (all existing tests + the new one). In particular the existing `testRejectsWhenExifTimestampOutsideEventWindow` (which dispatches WITHOUT the flag) must still fail-reject.

- [ ] **Step 6: Commit**

```bash
git add src/MessageHandler/ProcessPhotoHandler.php tests/Integration/MessageHandler/ProcessPhotoHandlerTest.php
git commit -m "112 - handler skips window guard and clears derivatives on re-ingest #112"
```

---

### Task 4: Audit actions + `PhotoController` re-ingest endpoints

Two POST actions (bulk + per-photo), both organizer-gated, CSRF-protected, audited, and refused unless the event retains originals.

**Files:**
- Modify: `src/Audit/AuditAction.php` (add two cases near the `Photo*` group, ~line 25)
- Modify: `src/Controller/Admin/PhotoController.php` (add two actions after `delete()`, before `loadOrThrow()`)
- Test: `tests/Functional/Admin/PhotoReingestTest.php` (create)

**Interfaces:**
- Consumes: `Photo::resetForReingest()` (Task 1), `ProcessPhoto(..., reingest: true)` (Task 2), `PhotoStatus::Ready`, `EventVoter::EDIT`, `PhotoVoter::EDIT`, existing `assertCsrf()` / `loadOrThrow()` / `$this->bus` / `$this->photos` / `$this->em` / `$this->audit`.
- Produces:
  - `AuditAction::PhotoReingest = 'photo.reingest'`, `AuditAction::PhotoReingestAll = 'photo.reingest_all'`.
  - Route `admin_photo_reingest_all`: `POST /admin/events/{id}/photos/reingest`, CSRF id `reingest_all_photos_<id>`, redirects to `admin_photo_grid`.
  - Route `admin_photo_reingest`: `POST /admin/events/{eventId}/photos/{photoId}/reingest`, CSRF id `reingest_photo_<photoId>`, redirects to `admin_photo_grid` with `page`.

- [ ] **Step 1: Add the audit cases**

In `src/Audit/AuditAction.php`, directly below `case PhotoDeleteAll = 'photo.delete_all';` add:

```php
    case PhotoReingest = 'photo.reingest';
    case PhotoReingestAll = 'photo.reingest_all';
```

(Leave the existing unused `case PhotoRetry` as-is.)

- [ ] **Step 2: Write the failing functional tests**

Create `tests/Functional/Admin/PhotoReingestTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\PhotoStatus;
use App\Entity\User;
use App\Message\ProcessPhoto;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

final class PhotoReingestTest extends WebTestCase
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

        $owner = new User('o@example.test', 'O');
        $owner->setPassword($hasher->hashPassword($owner, 'x'));
        $owner->addRole('ROLE_ORGANIZER');
        $this->em->persist($owner);
        $this->em->flush();

        $this->owner = $owner;
        $this->client->loginUser($owner);
    }

    private function makeEvent(bool $retainOriginals): Event
    {
        $event = new Event(
            'e' . bin2hex(random_bytes(4)),
            'E',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $this->owner,
        );
        $event->setTimezone('UTC');
        $event->setRetainOriginals($retainOriginals);
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    private function addReady(Event $event, string $hashSeed): Photo
    {
        $photo = new Photo($event, str_pad($hashSeed, 64, '0'), $hashSeed . '.jpg', 100);
        $photo->markReady(new DateTimeImmutable('now', new DateTimeZone('UTC')), 100, 100, 2048);
        $this->em->persist($photo);
        $this->em->flush();

        return $photo;
    }

    /** @return list<ProcessPhoto> */
    private function dispatched(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');

        return array_values(array_map(
            static fn(Envelope $e): object => $e->getMessage(),
            $transport->getSent(),
        ));
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

    public function testReingestAllResetsReadyPhotosAndDispatches(): void
    {
        $event = $this->makeEvent(retainOriginals: true);
        $ready1 = $this->addReady($event, 'aa');
        $ready2 = $this->addReady($event, 'bb');
        $pending = new Photo($event, str_pad('cc', 64, '0'), 'cc.jpg', 100);
        $this->em->persist($pending);
        $this->em->flush();

        $token = $this->primeCsrfToken('reingest_all_photos_' . $event->getId());
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/reingest', (int) $event->getId()),
            ['_token' => $token],
        );

        self::assertResponseRedirects(sprintf('/admin/events/%d/photos-grid', (int) $event->getId()));

        $this->em->clear();
        $this->assertSame(PhotoStatus::Pending, $this->em->find(Photo::class, $ready1->getId())->getStatus());
        $this->assertSame(PhotoStatus::Pending, $this->em->find(Photo::class, $ready2->getId())->getStatus());

        $messages = $this->dispatched();
        $this->assertCount(2, $messages, 'Only the two Ready photos are re-dispatched, not the Pending one.');
        foreach ($messages as $m) {
            $this->assertInstanceOf(ProcessPhoto::class, $m);
            $this->assertTrue($m->reingest, 'Bulk re-ingest must dispatch with reingest: true.');
        }
    }

    public function testReingestAllRefusedWhenNotRetainingOriginals(): void
    {
        $event = $this->makeEvent(retainOriginals: false);
        $ready = $this->addReady($event, 'aa');

        $token = $this->primeCsrfToken('reingest_all_photos_' . $event->getId());
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/reingest', (int) $event->getId()),
            ['_token' => $token],
        );

        self::assertResponseRedirects(sprintf('/admin/events/%d/photos-grid', (int) $event->getId()));
        $this->em->clear();
        $this->assertSame(PhotoStatus::Ready, $this->em->find(Photo::class, $ready->getId())->getStatus());
        $this->assertCount(0, $this->dispatched());
    }

    public function testReingestAllRejectsMissingCsrf(): void
    {
        $event = $this->makeEvent(retainOriginals: true);

        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/reingest', (int) $event->getId()),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testReingestSinglePhoto(): void
    {
        $event = $this->makeEvent(retainOriginals: true);
        $ready = $this->addReady($event, 'aa');

        $token = $this->primeCsrfToken('reingest_photo_' . $ready->getId());
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/%d/reingest', (int) $event->getId(), (int) $ready->getId()),
            ['_token' => $token],
        );

        self::assertResponseRedirects(sprintf('/admin/events/%d/photos-grid?page=1', (int) $event->getId()));
        $this->em->clear();
        $this->assertSame(PhotoStatus::Pending, $this->em->find(Photo::class, $ready->getId())->getStatus());

        $messages = $this->dispatched();
        $this->assertCount(1, $messages);
        $this->assertTrue($messages[0]->reingest);
    }

    public function testReingestSingleRefusedWhenNotReady(): void
    {
        $event = $this->makeEvent(retainOriginals: true);
        $pending = new Photo($event, str_pad('dd', 64, '0'), 'dd.jpg', 100);
        $this->em->persist($pending);
        $this->em->flush();

        $token = $this->primeCsrfToken('reingest_photo_' . $pending->getId());
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/%d/reingest', (int) $event->getId(), (int) $pending->getId()),
            ['_token' => $token],
        );

        self::assertResponseRedirects(sprintf('/admin/events/%d/photos-grid?page=1', (int) $event->getId()));
        $this->em->clear();
        $this->assertSame(PhotoStatus::Pending, $this->em->find(Photo::class, $pending->getId())->getStatus());
        $this->assertCount(0, $this->dispatched());
    }

    public function testReingestSingleRefusedWhenNotRetainingOriginals(): void
    {
        $event = $this->makeEvent(retainOriginals: false);
        $ready = $this->addReady($event, 'aa');

        $token = $this->primeCsrfToken('reingest_photo_' . $ready->getId());
        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/%d/reingest', (int) $event->getId(), (int) $ready->getId()),
            ['_token' => $token],
        );

        self::assertResponseRedirects(sprintf('/admin/events/%d/photos-grid?page=1', (int) $event->getId()));
        $this->em->clear();
        $this->assertSame(PhotoStatus::Ready, $this->em->find(Photo::class, $ready->getId())->getStatus());
        $this->assertCount(0, $this->dispatched());
    }

    public function testReingestSingleRejectsMissingCsrf(): void
    {
        $event = $this->makeEvent(retainOriginals: true);
        $ready = $this->addReady($event, 'aa');

        $this->client->request(
            Request::METHOD_POST,
            sprintf('/admin/events/%d/photos/%d/reingest', (int) $event->getId(), (int) $ready->getId()),
        );

        self::assertResponseStatusCodeSame(403);
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Functional/Admin/PhotoReingestTest.php`
Expected: FAIL — routes `admin_photo_reingest_all` / `admin_photo_reingest` don't exist yet (404s), so the redirect/dispatch assertions fail.

- [ ] **Step 4: Add the two controller actions**

In `src/Controller/Admin/PhotoController.php`, insert these two methods after `delete()` (line 256) and before `loadOrThrow()` (line 258):

```php
    #[Route(
        '/admin/events/{id}/photos/reingest',
        name: 'admin_photo_reingest_all',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[Audited(AuditAction::PhotoReingestAll, targetParam: 'id', targetType: 'Event')]
    public function reingestAll(Event $event, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);
        $this->assertCsrf($request, 'reingest_all_photos_' . $event->getId());

        $eventId = (int) $event->getId();

        if (!$event->isRetainOriginals()) {
            $this->addFlash('error', 'Re-ingest is unavailable: this event does not retain originals.');

            return $this->redirectToRoute('admin_photo_grid', ['id' => $eventId]);
        }

        /** @var list<Photo> $ready */
        $ready = $this->photos->findBy(['event' => $event, 'status' => PhotoStatus::Ready]);
        foreach ($ready as $photo) {
            $photo->resetForReingest();
            $this->bus->dispatch(new ProcessPhoto((int) $photo->getId(), reingest: true));
        }

        $this->em->flush();
        $this->audit->set('reingested_count', count($ready));
        $this->addFlash('success', sprintf('Re-ingesting %d photos.', count($ready)));

        return $this->redirectToRoute('admin_photo_grid', ['id' => $eventId]);
    }

    #[Route(
        '/admin/events/{eventId}/photos/{photoId}/reingest',
        name: 'admin_photo_reingest',
        requirements: ['eventId' => '\d+', 'photoId' => '\d+'],
        methods: ['POST'],
    )]
    #[Audited(AuditAction::PhotoReingest, targetParam: 'photoId', targetType: 'Photo')]
    public function reingest(int $eventId, int $photoId, Request $request): RedirectResponse
    {
        $photo = $this->loadOrThrow($eventId, $photoId);
        $this->denyAccessUnlessGranted(PhotoVoter::EDIT, $photo);
        $this->assertCsrf($request, 'reingest_photo_' . $photoId);

        $page  = max(1, $request->request->getInt('page', 1));
        $target = ['id' => $eventId, 'page' => $page];

        if (!$photo->getEvent()->isRetainOriginals()) {
            $this->addFlash('error', 'Re-ingest is unavailable: this event does not retain originals.');

            return $this->redirectToRoute('admin_photo_grid', $target);
        }

        if ($photo->getStatus() !== PhotoStatus::Ready) {
            $this->addFlash('error', 'Only ready photos can be re-ingested.');

            return $this->redirectToRoute('admin_photo_grid', $target);
        }

        $photo->resetForReingest();
        $this->bus->dispatch(new ProcessPhoto($photoId, reingest: true));
        $this->em->flush();

        return $this->redirectToRoute('admin_photo_grid', $target);
    }
```

`Photo` and `PhotoStatus` are already imported. `EventVoter`, `PhotoVoter`, `ProcessPhoto`, `Audited`, `AuditAction`, `RedirectResponse`, `Request`, `Route` are all already imported.

- [ ] **Step 5: Run the functional tests to verify they pass**

Run: `vendor/bin/phpunit tests/Functional/Admin/PhotoReingestTest.php`
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Audit/AuditAction.php src/Controller/Admin/PhotoController.php tests/Functional/Admin/PhotoReingestTest.php
git commit -m "112 - add bulk and per-photo re-ingest controller endpoints #112"
```

---

### Task 5: UI controls (bulk + per-photo buttons)

Surface both actions, only when the event retains originals. Per-photo button only on `Ready` rows.

**Files:**
- Modify: `templates/admin/event/_photo_row.html.twig` (actions cell)
- Modify: `templates/admin/event/photos_grid.html.twig` (bulk-actions block near the bottom)
- Test: `tests/Functional/Admin/PhotoReingestUiTest.php` (create)

**Interfaces:**
- Consumes: routes `admin_photo_reingest` / `admin_photo_reingest_all` and their CSRF token ids (Task 4). Template vars `event`, `photo`, `page` (already passed to `_photo_row.html.twig`), and `total` (already in `photos_grid.html.twig`).
- Produces: no code interface — rendered HTML only.

- [ ] **Step 1: Write the failing UI tests**

Create `tests/Functional/Admin/PhotoReingestUiTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PhotoReingestUiTest extends WebTestCase
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

        $owner = new User('o@example.test', 'O');
        $owner->setPassword($hasher->hashPassword($owner, 'x'));
        $owner->addRole('ROLE_ORGANIZER');
        $this->em->persist($owner);
        $this->em->flush();
        $this->owner = $owner;
        $this->client->loginUser($owner);
    }

    private function makeEventWithReady(bool $retainOriginals): Event
    {
        $event = new Event(
            'e' . bin2hex(random_bytes(4)),
            'E',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $this->owner,
        );
        $event->setTimezone('UTC');
        $event->setRetainOriginals($retainOriginals);
        $this->em->persist($event);

        $photo = new Photo($event, str_repeat('a', 64), 'a.jpg', 100);
        $photo->markReady(new DateTimeImmutable('now', new DateTimeZone('UTC')), 100, 100, 2048);
        $this->em->persist($photo);
        $this->em->flush();

        return $event;
    }

    public function testReingestControlsShownWhenRetaining(): void
    {
        $event = $this->makeEventWithReady(retainOriginals: true);

        $this->client->request(Request::METHOD_GET, sprintf('/admin/events/%d/photos-grid', (int) $event->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('form[action$="/events/%d/photos/reingest"]', (int) $event->getId()));
        self::assertStringContainsString('/reingest', (string) $this->client->getResponse()->getContent());
    }

    public function testReingestControlsHiddenWhenNotRetaining(): void
    {
        $event = $this->makeEventWithReady(retainOriginals: false);

        $this->client->request(Request::METHOD_GET, sprintf('/admin/events/%d/photos-grid', (int) $event->getId()));

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/reingest', (string) $this->client->getResponse()->getContent());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Functional/Admin/PhotoReingestUiTest.php`
Expected: FAIL — `testReingestControlsShownWhenRetaining` fails (no `/reingest` markup yet).

- [ ] **Step 3: Add the per-photo button**

In `templates/admin/event/_photo_row.html.twig`, inside the actions cell (`<div class="inline-flex items-center gap-1">`), add this block immediately after the closing `{% endif %}` of the `View` link and before the Delete `<form>`:

```twig
            {% if event.retainOriginals and photo.status.value == 'ready' %}
                <form method="post"
                      action="{{ path('admin_photo_reingest', {eventId: event.id, photoId: photo.id}) }}"
                      onsubmit="return confirm('Re-ingest this photo? Its thumbnail and preview will be regenerated from the original.');"
                      class="inline">
                    <input type="hidden" name="_token" value="{{ csrf_token('reingest_photo_' ~ photo.id) }}">
                    <input type="hidden" name="page" value="{{ page|default(1) }}">
                    <button class="btn btn-ghost btn-xs">Re-ingest</button>
                </form>
            {% endif %}
```

- [ ] **Step 4: Add the bulk button**

In `templates/admin/event/photos_grid.html.twig`, replace the opening of the bottom bulk block (currently `{% if total > 0 %}` wrapping the delete-all control) so the re-ingest button sits alongside delete-all. Change the wrapper so it reads:

```twig
    {% if total > 0 %}
        <div class="mt-6 flex items-center justify-end gap-2 border-t border-base-300 pt-4">
            {% if event.retainOriginals %}
                <form method="post"
                      action="{{ path('admin_photo_reingest_all', {id: event.id}) }}"
                      onsubmit="return confirm('Re-ingest all {{ total }} photo{{ total != 1 ? 's' : '' }}? Every thumbnail and preview will be regenerated from the originals using the current display settings.');"
                      class="inline">
                    <input type="hidden" name="_token" value="{{ csrf_token('reingest_all_photos_' ~ event.id) }}">
                    <button type="submit" class="btn btn-outline btn-sm">Re-ingest all photos</button>
                </form>
            {% endif %}
            <div data-controller="delete-all-photos"
                 data-delete-all-photos-expected-value="{{ event.name }}"
                 class="inline">
```

Then, at the end of that block, ensure the extra `<div>` you opened is closed: the existing delete-all `</div>` closes the `data-controller` wrapper; add one more `</div>` before the final `{% endif %}` to close the new flex container. Verify the tag balance by viewing the rendered page in Step 6.

- [ ] **Step 5: Run the UI tests to verify they pass**

Run: `vendor/bin/phpunit tests/Functional/Admin/PhotoReingestUiTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Manually verify tag balance / rendering (optional but recommended)**

Run: `vendor/bin/phpunit --filter testReingestControlsShownWhenRetaining tests/Functional/Admin/PhotoReingestUiTest.php`
If it passes, the frame rendered without a Twig syntax error, confirming the tags balance.

- [ ] **Step 7: Commit**

```bash
git add templates/admin/event/_photo_row.html.twig templates/admin/event/photos_grid.html.twig tests/Functional/Admin/PhotoReingestUiTest.php
git commit -m "112 - add re-ingest UI controls (bulk + per-photo), gated on retainOriginals #112"
```

---

### Task 6: Full-suite + quality-gate verification

**Files:** none (verification only).

- [ ] **Step 1: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: PASS, no deprecations/notices/warnings (the suite is configured `failOnDeprecation`/`failOnNotice`/`failOnWarning`).

- [ ] **Step 2: Run the full quality gate**

Run: `vendor/bin/grumphp run`
Expected: all tasks green — `phpstan` (level 10), `phpcs` (PSR-12), `phpmnd`, `phpcpd`, `rector`, `doctrine:schema:validate`.

If `phpmnd` flags the literal `1` in `max(1, ...)` or pluralization, it is in phpmnd's default ignore set; if the config is stricter, extract a small named constant. If `phpcpd` flags duplication between `reingestAll` and `reingest` (the retain-originals guard), that is acceptable — they differ in redirect target and are short; only refactor if the gate actually fails.

- [ ] **Step 3: Final commit if any gate auto-fixed formatting**

```bash
git add -A
git commit -m "112 - satisfy quality gates for re-ingest feature #112"
```

---

## Self-Review

**Spec coverage:**
- Precondition (retain-originals gate) → Task 4 (controller guard) + Task 5 (UI gate). ✅
- `Ready → Pending` explicit transition → Task 1. ✅
- Delete derivatives + re-dispatch → Task 3 (handler delete) + Task 4 (dispatch). ✅
- Skip window guard on re-ingest → Task 3. ✅
- Honour current preview settings → Task 3 (uses `$event->getPreviewSettings()`, asserted via regenerated derivatives + byte accounting). ✅
- No orphaned/stale derivatives → Task 2 (`delete()`) + Task 3 (delete-then-generate, deterministic path). ✅
- Bulk + per-photo UI → Task 5. ✅
- Ready-only scope; skip in-flight Pending → Task 4 (`findBy status = Ready`), asserted in `testReingestAllResetsReadyPhotosAndDispatches`. ✅
- Failed out of scope; no retry route → honoured (no `/retry` added; `testRetryRouteIsGone` untouched). ✅

**Placeholder scan:** No TBD/TODO; every code and test step shows complete content. The one soft spot — the property names inside `DerivativeGenerator` (`$this->thumbs` / `$this->previews`) — is called out in Task 2 Step 4 with instructions to match the actual constructor.

**Type consistency:** `resetForReingest()`, `ProcessPhoto(int, bool $reingest = false)`, `DerivativeGenerator::delete(string)`, `AuditAction::PhotoReingest`/`PhotoReingestAll`, route names `admin_photo_reingest`/`admin_photo_reingest_all`, and CSRF ids `reingest_photo_<id>`/`reingest_all_photos_<id>` are used identically across tasks and tests.
