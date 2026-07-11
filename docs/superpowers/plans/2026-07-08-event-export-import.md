# Event Export / Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add admin Export (download a `.zip` of one event: settings, photo derivatives, subscribers) and Import (upload that `.zip` to recreate the event under the importer, or an admin-chosen owner), refusing import when the slug already exists.

**Architecture:** Two stateless services — `EventArchiveExporter` (Event → temp ZIP) and `EventArchiveImporter` (ZIP + owner → new Event, transactional). A `EventArchiveManifest` value object (+ small `ManifestEvent`/`ManifestPhoto`/`ManifestSubscription` DTOs) owns the JSON schema and its validation. Two new `Admin\EventController` actions wire them to routes; a non-entity `EventImportType` form carries the upload + admin owner selector. No DB migration.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3, Flysystem (local disks), PHP `ZipArchive`, PHPUnit 13.

Spec: `docs/superpowers/specs/2026-07-08-101-event-export-import-design.md`.

## Global Constraints

- Branch: `feature/101-event-export-import` (off `main`). Already created.
- PHP attributes only (no annotations). `declare(strict_types=1);` in every file.
- GrumPHP gates every commit: **phpstan level 10**, **phpcs PSR-12**, **phpmnd** (no magic numbers in `src/` — use named constants), **phpcpd** (no 50-line/100-token dup), **rector**, **securitychecker**, `doctrine:schema:validate --skip-sync`.
- No DB schema change and **no migration** in this feature.
- Photos are recreated directly in `Ready` state from bundled derivatives — **never** dispatch `ProcessPhoto` on import.
- Subscription **tokens are regenerated** on import (never copy source tokens).
- Publish state (`publishedAt`, `notificationsEnabled`) is **preserved verbatim**.
- Slug already exists → **refuse** (no rename, no overwrite).
- Only `Ready` photos are exported; `skippedPhotos` counts the rest.
- Commits: the **user** runs `git commit` (do not auto-commit). Each commit message must contain `#101`. Commit steps below are for the human executor; an agent stages and proposes the message.
- Storage disks (autowire by service id): `event_logos_storage`, `photo_thumbs_storage`, `photo_previews_storage`. Photo derivative path template in each: `event-<eventId>/<photoId>.jpg`.

## File Structure

**Create**
- `src/Service/Event/Archive/ManifestPhoto.php` — readonly DTO for one photo.
- `src/Service/Event/Archive/ManifestSubscription.php` — readonly DTO for one subscriber.
- `src/Service/Event/Archive/ManifestEvent.php` — readonly DTO for event scalars + style + logo.
- `src/Service/Event/Archive/EventArchiveManifest.php` — top-level manifest: `FORMAT`/`VERSION`, `toArray()`, `fromArray()`, JSON (de)serialize + validation.
- `src/Service/Event/Archive/InvalidArchiveException.php` — thrown on any malformed archive/manifest.
- `src/Service/Event/Archive/SlugAlreadyExistsException.php` — thrown by the importer on slug collision.
- `src/Service/Event/EventArchiveExporter.php` — Event → temp ZIP path.
- `src/Service/Event/EventArchiveImporter.php` — ZIP path + owner → new Event (transactional).
- `src/Form/EventImportType.php` — upload field + admin owner selector (non-entity form).
- `templates/admin/event/import.html.twig` — upload page.
- `tests/Unit/Entity/EventNotificationSubscriptionReconstituteTest.php`
- `tests/Unit/Service/Event/Archive/EventArchiveManifestTest.php`
- `tests/Integration/Service/Event/EventArchiveRoundtripTest.php`
- `tests/Functional/Admin/EventExportTest.php`
- `tests/Functional/Admin/EventImportTest.php`

**Modify**
- `src/Entity/EventNotificationSubscription.php` — add `reconstituteForImport(...)` static factory.
- `src/Audit/AuditAction.php` — add `EventExport`, `EventImport` cases.
- `src/Controller/Admin/EventController.php` — add `export()` and `import()` actions + constructor deps.
- `templates/admin/event/index.html.twig` — per-row Export link + top-of-page Import link.

---

### Task 1: Faithful subscription reconstitution factory

`EventNotificationSubscription` has no setters; its status is only reachable via `confirm()`/`unsubscribe()` which stamp *now* and can throw on expiry. Import needs to restore an arbitrary status + original timestamps while **regenerating tokens**. Add a static factory that builds via the constructor (fresh tokens) then sets the persisted state directly.

**Files:**
- Modify: `src/Entity/EventNotificationSubscription.php`
- Test: `tests/Unit/Entity/EventNotificationSubscriptionReconstituteTest.php`

**Interfaces:**
- Produces: `EventNotificationSubscription::reconstituteForImport(Event $event, string $email, EventNotificationStatus $status, DateTimeImmutable $createdAt, ?DateTimeImmutable $confirmedAt, ?DateTimeImmutable $unsubscribedAt, ?DateTimeImmutable $notifiedAt): self`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Entity/EventNotificationSubscriptionReconstituteTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Event;
use App\Entity\EventNotificationStatus;
use App\Entity\EventNotificationSubscription;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class EventNotificationSubscriptionReconstituteTest extends TestCase
{
    private function event(): Event
    {
        $utc = new DateTimeZone('UTC');

        return new Event(
            'slug-x',
            'Event X',
            new DateTimeImmutable('2026-01-01 10:00:00', $utc),
            new DateTimeImmutable('2026-01-01 12:00:00', $utc),
            new User('owner@example.com', 'Owner'),
        );
    }

    public function testConfirmedIsRestoredWithTimestampsAndNoConfirmationToken(): void
    {
        $utc       = new DateTimeZone('UTC');
        $created   = new DateTimeImmutable('2026-01-02 09:00:00', $utc);
        $confirmed = new DateTimeImmutable('2026-01-02 09:05:00', $utc);

        $sub = EventNotificationSubscription::reconstituteForImport(
            $this->event(),
            'Visitor@Example.com',
            EventNotificationStatus::Confirmed,
            $created,
            $confirmed,
            null,
            null,
        );

        self::assertSame('visitor@example.com', $sub->getEmail());
        self::assertSame(EventNotificationStatus::Confirmed, $sub->getStatus());
        self::assertNull($sub->getConfirmationToken());
        self::assertNotSame('', $sub->getUnsubscribeToken());
    }

    public function testPendingKeepsAFreshConfirmationToken(): void
    {
        $created = new DateTimeImmutable('2026-01-02 09:00:00', new DateTimeZone('UTC'));

        $sub = EventNotificationSubscription::reconstituteForImport(
            $this->event(),
            'p@example.com',
            EventNotificationStatus::Pending,
            $created,
            null,
            null,
            null,
        );

        self::assertSame(EventNotificationStatus::Pending, $sub->getStatus());
        self::assertNotNull($sub->getConfirmationToken());
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `vendor/bin/phpunit tests/Unit/Entity/EventNotificationSubscriptionReconstituteTest.php`
Expected: FAIL — `Call to undefined method ...::reconstituteForImport()`.

- [ ] **Step 3: Add the factory**

In `src/Entity/EventNotificationSubscription.php`, add this method (after the constructor, before `confirm()`):

```php
    /**
     * Rebuild a subscription from an event-export archive: fresh tokens (source
     * tokens never travel), but the original status and timestamps restored
     * directly — the normal confirm()/unsubscribe() API would overwrite them
     * with "now" and can reject expired confirmations.
     */
    public static function reconstituteForImport(
        Event $event,
        string $email,
        EventNotificationStatus $status,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $confirmedAt,
        ?DateTimeImmutable $unsubscribedAt,
        ?DateTimeImmutable $notifiedAt,
    ): self {
        $sub = new self($event, $email, $createdAt);

        $sub->status         = $status;
        $sub->confirmedAt    = $confirmedAt;
        $sub->unsubscribedAt = $unsubscribedAt;
        $sub->notifiedAt     = $notifiedAt;

        if ($status !== EventNotificationStatus::Pending) {
            // Mirror the state-machine invariant: only pending rows carry a
            // live confirmation token / expiry.
            $sub->confirmationToken     = null;
            $sub->confirmationExpiresAt = null;
        }

        return $sub;
    }
```

- [ ] **Step 4: Run the test to confirm it passes**

Run: `vendor/bin/phpunit tests/Unit/Entity/EventNotificationSubscriptionReconstituteTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Static analysis + commit**

Run: `vendor/bin/phpstan analyse src/Entity/EventNotificationSubscription.php`
Expected: no errors.

```bash
git add src/Entity/EventNotificationSubscription.php tests/Unit/Entity/EventNotificationSubscriptionReconstituteTest.php
git commit -m "101 - add reconstituteForImport factory to EventNotificationSubscription"
```

---

### Task 2: Manifest value objects + JSON schema

The manifest is the archive's contract. Model it as readonly DTOs plus a top-level `EventArchiveManifest` that owns `FORMAT`/`VERSION` and validates on read. Dates are ISO-8601 strings in the manifest; entities convert at the exporter/importer boundary.

**Files:**
- Create: `src/Service/Event/Archive/ManifestPhoto.php`, `ManifestSubscription.php`, `ManifestEvent.php`, `EventArchiveManifest.php`, `InvalidArchiveException.php`
- Test: `tests/Unit/Service/Event/Archive/EventArchiveManifestTest.php`

**Interfaces:**
- Produces:
  - `ManifestPhoto` readonly: `string $contentHash, string $originalFilename, int $byteSize, int $width, int $height, ?string $takenAt, int $derivativeBytes, string $createdAt`
  - `ManifestSubscription` readonly: `string $email, string $status, ?string $confirmedAt, ?string $unsubscribedAt, ?string $notifiedAt, string $createdAt`
  - `ManifestEvent` readonly: `string $name, string $slug, ?string $description, string $timezone, string $startsAt, string $endsAt, ?string $publishedAt, bool $notificationsEnabled, ?string $fontColor, ?string $backgroundColor, ?string $buttonColor, ?bool $glowEnabled, ?string $logoFilename`
  - `EventArchiveManifest`: `const string FORMAT='eventphotos.event-export'; const int VERSION=1;` constructor `(string $exportedAt, string $sourceInstance, ManifestEvent $event, list<ManifestPhoto> $photos, list<ManifestSubscription> $subscriptions, int $skippedPhotos)`; `toArray(): array<string,mixed>`; `toJson(): string`; `static fromJson(string $json): self` (throws `InvalidArchiveException`).
  - `InvalidArchiveException extends RuntimeException`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/Event/Archive/EventArchiveManifestTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Event\Archive;

use App\Service\Event\Archive\EventArchiveManifest;
use App\Service\Event\Archive\InvalidArchiveException;
use App\Service\Event\Archive\ManifestEvent;
use App\Service\Event\Archive\ManifestPhoto;
use App\Service\Event\Archive\ManifestSubscription;
use PHPUnit\Framework\TestCase;

final class EventArchiveManifestTest extends TestCase
{
    private function manifest(): EventArchiveManifest
    {
        return new EventArchiveManifest(
            '2026-07-08T10:00:00+00:00',
            'https://events.peakcapture.io',
            new ManifestEvent(
                'My Event', 'my-event-abc123', 'Desc', 'Europe/Amsterdam',
                '2026-01-01T10:00:00+00:00', '2026-01-01T12:00:00+00:00',
                '2026-01-01T13:00:00+00:00', true,
                '#111111', '#eeeeee', '#3366ff', true, 'logo.png',
            ),
            [new ManifestPhoto('a'.str_repeat('0', 63), 'IMG.jpg', 1000, 4000, 3000, '2026-01-01T11:00:00+00:00', 200000, '2026-01-01T11:05:00+00:00')],
            [new ManifestSubscription('v@example.com', 'confirmed', '2026-01-01T11:10:00+00:00', null, null, '2026-01-01T11:00:00+00:00')],
            2,
        );
    }

    public function testJsonRoundTrip(): void
    {
        $restored = EventArchiveManifest::fromJson($this->manifest()->toJson());

        self::assertSame('my-event-abc123', $restored->event->slug);
        self::assertSame(true, $restored->event->notificationsEnabled);
        self::assertCount(1, $restored->photos);
        self::assertSame('confirmed', $restored->subscriptions[0]->status);
        self::assertSame(2, $restored->skippedPhotos);
    }

    public function testRejectsUnknownFormat(): void
    {
        $this->expectException(InvalidArchiveException::class);
        EventArchiveManifest::fromJson('{"format":"nope","version":1}');
    }

    public function testRejectsFutureVersion(): void
    {
        $this->expectException(InvalidArchiveException::class);
        EventArchiveManifest::fromJson('{"format":"eventphotos.event-export","version":999}');
    }

    public function testRejectsNonJson(): void
    {
        $this->expectException(InvalidArchiveException::class);
        EventArchiveManifest::fromJson('not json');
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/Event/Archive/EventArchiveManifestTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Create the exception**

`src/Service/Event/Archive/InvalidArchiveException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Event\Archive;

use RuntimeException;

final class InvalidArchiveException extends RuntimeException
{
}
```

- [ ] **Step 4: Create the three DTOs**

`src/Service/Event/Archive/ManifestPhoto.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Event\Archive;

final readonly class ManifestPhoto
{
    public function __construct(
        public string $contentHash,
        public string $originalFilename,
        public int $byteSize,
        public int $width,
        public int $height,
        public ?string $takenAt,
        public int $derivativeBytes,
        public string $createdAt,
    ) {
    }
}
```

`src/Service/Event/Archive/ManifestSubscription.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Event\Archive;

final readonly class ManifestSubscription
{
    public function __construct(
        public string $email,
        public string $status,
        public ?string $confirmedAt,
        public ?string $unsubscribedAt,
        public ?string $notifiedAt,
        public string $createdAt,
    ) {
    }
}
```

`src/Service/Event/Archive/ManifestEvent.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Event\Archive;

final readonly class ManifestEvent
{
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
    ) {
    }
}
```

- [ ] **Step 5: Create `EventArchiveManifest`**

`src/Service/Event/Archive/EventArchiveManifest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Event\Archive;

use JsonException;

final readonly class EventArchiveManifest
{
    public const string FORMAT = 'eventphotos.event-export';

    public const int VERSION = 1;

    /**
     * @param list<ManifestPhoto>        $photos
     * @param list<ManifestSubscription> $subscriptions
     */
    public function __construct(
        public string $exportedAt,
        public string $sourceInstance,
        public ManifestEvent $event,
        public array $photos,
        public array $subscriptions,
        public int $skippedPhotos,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format'         => self::FORMAT,
            'version'        => self::VERSION,
            'exportedAt'     => $this->exportedAt,
            'sourceInstance' => $this->sourceInstance,
            'event'          => [
                'name'                 => $this->event->name,
                'slug'                 => $this->event->slug,
                'description'          => $this->event->description,
                'timezone'             => $this->event->timezone,
                'startsAt'             => $this->event->startsAt,
                'endsAt'               => $this->event->endsAt,
                'publishedAt'          => $this->event->publishedAt,
                'notificationsEnabled' => $this->event->notificationsEnabled,
                'style'                => [
                    'fontColor'       => $this->event->fontColor,
                    'backgroundColor' => $this->event->backgroundColor,
                    'buttonColor'     => $this->event->buttonColor,
                    'glowEnabled'     => $this->event->glowEnabled,
                ],
                'logo' => $this->event->logoFilename === null
                    ? null
                    : ['filename' => $this->event->logoFilename],
            ],
            'photos'        => array_map(static fn (ManifestPhoto $p): array => [
                'contentHash'     => $p->contentHash,
                'originalFilename' => $p->originalFilename,
                'byteSize'        => $p->byteSize,
                'width'           => $p->width,
                'height'          => $p->height,
                'takenAt'         => $p->takenAt,
                'derivativeBytes' => $p->derivativeBytes,
                'createdAt'       => $p->createdAt,
            ], $this->photos),
            'subscriptions' => array_map(static fn (ManifestSubscription $s): array => [
                'email'          => $s->email,
                'status'         => $s->status,
                'confirmedAt'    => $s->confirmedAt,
                'unsubscribedAt' => $s->unsubscribedAt,
                'notifiedAt'     => $s->notifiedAt,
                'createdAt'      => $s->createdAt,
            ], $this->subscriptions),
            'skippedPhotos' => $this->skippedPhotos,
        ];
    }

    public function toJson(): string
    {
        try {
            return json_encode(
                $this->toArray(),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $e) {
            throw new InvalidArchiveException('Could not encode manifest.', 0, $e);
        }
    }

    public static function fromJson(string $json): self
    {
        try {
            /** @var mixed $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArchiveException('Manifest is not valid JSON.', 0, $e);
        }

        if (!is_array($data)) {
            throw new InvalidArchiveException('Manifest root must be an object.');
        }

        if (($data['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArchiveException('Unrecognised archive format.');
        }

        if (($data['version'] ?? null) !== self::VERSION) {
            throw new InvalidArchiveException('Unsupported archive version.');
        }

        $event         = self::readArray($data, 'event');
        $style         = self::readArray($event, 'style');
        $logo          = isset($event['logo']) && is_array($event['logo']) ? $event['logo'] : null;

        $manifestEvent = new ManifestEvent(
            self::str($event, 'name'),
            self::str($event, 'slug'),
            self::nullableStr($event, 'description'),
            self::str($event, 'timezone'),
            self::str($event, 'startsAt'),
            self::str($event, 'endsAt'),
            self::nullableStr($event, 'publishedAt'),
            (bool) ($event['notificationsEnabled'] ?? false),
            self::nullableStr($style, 'fontColor'),
            self::nullableStr($style, 'backgroundColor'),
            self::nullableStr($style, 'buttonColor'),
            isset($style['glowEnabled']) ? (bool) $style['glowEnabled'] : null,
            $logo === null ? null : self::nullableStr($logo, 'filename'),
        );

        $photos = [];
        foreach (self::readList($data, 'photos') as $row) {
            $photos[] = new ManifestPhoto(
                self::str($row, 'contentHash'),
                self::str($row, 'originalFilename'),
                (int) ($row['byteSize'] ?? 0),
                (int) ($row['width'] ?? 0),
                (int) ($row['height'] ?? 0),
                self::nullableStr($row, 'takenAt'),
                (int) ($row['derivativeBytes'] ?? 0),
                self::str($row, 'createdAt'),
            );
        }

        $subscriptions = [];
        foreach (self::readList($data, 'subscriptions') as $row) {
            $subscriptions[] = new ManifestSubscription(
                self::str($row, 'email'),
                self::str($row, 'status'),
                self::nullableStr($row, 'confirmedAt'),
                self::nullableStr($row, 'unsubscribedAt'),
                self::nullableStr($row, 'notifiedAt'),
                self::str($row, 'createdAt'),
            );
        }

        return new self(
            self::str($data, 'exportedAt'),
            self::str($data, 'sourceInstance'),
            $manifestEvent,
            $photos,
            $subscriptions,
            (int) ($data['skippedPhotos'] ?? 0),
        );
    }

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function readArray(array $data, string $key): array
    {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            throw new InvalidArchiveException(sprintf('Manifest "%s" must be an object.', $key));
        }

        /** @var array<string, mixed> $out */
        $out = $data[$key];

        return $out;
    }

    /**
     * @param  array<string, mixed>       $data
     * @return list<array<string, mixed>>
     */
    private static function readList(array $data, string $key): array
    {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            return [];
        }

        $out = [];
        foreach ($data[$key] as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $out[] = $row;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $data */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArchiveException(sprintf('Manifest field "%s" must be a string.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function nullableStr(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
```

- [ ] **Step 6: Run the test to confirm it passes**

Run: `vendor/bin/phpunit tests/Unit/Service/Event/Archive/EventArchiveManifestTest.php`
Expected: PASS (4 tests).

- [ ] **Step 7: Static analysis + commit**

Run: `vendor/bin/phpstan analyse src/Service/Event/Archive`
Expected: no errors.

```bash
git add src/Service/Event/Archive tests/Unit/Service/Event/Archive
git commit -m "101 - add event-export manifest schema (DTOs + JSON validation)"
```

---

### Task 3: `EventArchiveExporter`

Builds a manifest from an `Event`, writes a temp ZIP with `manifest.json`, each **Ready** photo's thumb + preview, and the logo if present. Returns the temp file path (controller streams + deletes it).

**Files:**
- Create: `src/Service/Event/EventArchiveExporter.php`, `src/Service/Event/Archive/SlugAlreadyExistsException.php` (used in Task 4 but created here so the namespace is complete)
- Test: covered by the roundtrip integration test in Task 4 (exporter output is the importer's input; a standalone exporter assertion is included there).

**Interfaces:**
- Consumes: `EventArchiveManifest`, `ManifestEvent`, `ManifestPhoto`, `ManifestSubscription`, `Event`, `Photo`, `EventNotificationSubscription`.
- Produces: `EventArchiveExporter::export(Event $event): string` (absolute temp `.zip` path).

- [ ] **Step 1: Create the slug-collision exception**

`src/Service/Event/Archive/SlugAlreadyExistsException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Event\Archive;

use RuntimeException;

final class SlugAlreadyExistsException extends RuntimeException
{
    public function __construct(public readonly string $slug)
    {
        parent::__construct(sprintf('An event with slug "%s" already exists.', $slug));
    }
}
```

- [ ] **Step 2: Implement the exporter**

`src/Service/Event/EventArchiveExporter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Event;

use App\Entity\Event;
use App\Entity\EventNotificationSubscription;
use App\Entity\Photo;
use App\Entity\PhotoStatus;
use App\Repository\EventNotificationSubscriptionRepository;
use App\Repository\PhotoRepository;
use App\Service\Event\Archive\EventArchiveManifest;
use App\Service\Event\Archive\ManifestEvent;
use App\Service\Event\Archive\ManifestPhoto;
use App\Service\Event\Archive\ManifestSubscription;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use League\Flysystem\FilesystemOperator;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use ZipArchive;

final readonly class EventArchiveExporter
{
    private const string TMP_PREFIX = 'evt-export-';

    public function __construct(
        private PhotoRepository $photos,
        private EventNotificationSubscriptionRepository $subscriptions,
        #[Autowire(service: 'photo_thumbs_storage')]
        private FilesystemOperator $thumbs,
        #[Autowire(service: 'photo_previews_storage')]
        private FilesystemOperator $previews,
        #[Autowire(service: 'event_logos_storage')]
        private FilesystemOperator $logos,
        #[Autowire('%env(default::DEFAULT_URI)%')]
        private string $sourceInstance = '',
    ) {
    }

    public function export(Event $event): string
    {
        /** @var list<Photo> $allPhotos */
        $allPhotos = $this->photos->findBy(['event' => $event], ['id' => 'ASC']);
        $ready     = array_values(array_filter(
            $allPhotos,
            static fn (Photo $p): bool => $p->getStatus() === PhotoStatus::Ready,
        ));

        $zipPath = tempnam(sys_get_temp_dir(), self::TMP_PREFIX);
        if ($zipPath === false) {
            throw new RuntimeException('Could not allocate a temp file for the export.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open the export archive for writing.');
        }

        $eventId       = (int) $event->getId();
        $manifestPhotos = [];

        foreach ($ready as $photo) {
            $photoId = (int) $photo->getId();
            $path    = sprintf('event-%d/%d.jpg', $eventId, $photoId);
            $hash    = $photo->getContentHash();

            $zip->addFromString('photos/' . $hash . '.thumb.jpg', $this->thumbs->read($path));
            $zip->addFromString('photos/' . $hash . '.preview.jpg', $this->previews->read($path));

            $manifestPhotos[] = new ManifestPhoto(
                $hash,
                $photo->getOriginalFilename(),
                $photo->getByteSize(),
                $photo->getWidth() ?? 0,
                $photo->getHeight() ?? 0,
                self::iso($photo->getTakenAt()),
                $photo->getDerivativeBytes() ?? 0,
                self::iso($photo->getCreatedAt()) ?? '',
            );
        }

        $logoFilename = $event->getLogoFilename();
        if ($logoFilename !== null) {
            $zip->addFromString('images/logo/' . basename($logoFilename), $this->logos->read($logoFilename));
        }

        $manifest = new EventArchiveManifest(
            self::iso(new DateTimeImmutable('now', new DateTimeZone('UTC'))) ?? '',
            $this->sourceInstance,
            $this->buildManifestEvent($event, $logoFilename),
            $manifestPhotos,
            $this->buildManifestSubscriptions($event),
            count($allPhotos) - count($ready),
        );

        $zip->addFromString('manifest.json', $manifest->toJson());
        $zip->close();

        return $zipPath;
    }

    private function buildManifestEvent(Event $event, ?string $logoFilename): ManifestEvent
    {
        $style = $event->getStyle();

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
        );
    }

    /**
     * @return list<ManifestSubscription>
     */
    private function buildManifestSubscriptions(Event $event): array
    {
        /** @var list<EventNotificationSubscription> $subs */
        $subs = $this->subscriptions->findBy(['event' => $event], ['id' => 'ASC']);

        return array_map(static fn (EventNotificationSubscription $s): ManifestSubscription => new ManifestSubscription(
            $s->getEmail(),
            $s->getStatus()->value,
            self::iso($s->getConfirmedAt()),
            self::iso($s->getUnsubscribedAt()),
            self::iso($s->getNotifiedAt()),
            self::iso($s->getCreatedAt()) ?? '',
        ), $subs);
    }

    private static function iso(?DateTimeInterface $value): ?string
    {
        return $value?->format(DateTimeInterface::ATOM);
    }
}
```

- [ ] **Step 3: Add the getters the exporter needs on `EventNotificationSubscription`**

The exporter reads `getConfirmedAt()`, `getUnsubscribedAt()`, `getCreatedAt()` — `getNotifiedAt()` already exists. In `src/Entity/EventNotificationSubscription.php`, add (next to `getNotifiedAt()`):

```php
    public function getConfirmedAt(): ?DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function getUnsubscribedAt(): ?DateTimeImmutable
    {
        return $this->unsubscribedAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
```

- [ ] **Step 4: Static analysis + commit**

Run: `vendor/bin/phpstan analyse src/Service/Event/EventArchiveExporter.php src/Entity/EventNotificationSubscription.php`
Expected: no errors. (Exporter behaviour is verified end-to-end in Task 4.)

```bash
git add src/Service/Event/EventArchiveExporter.php src/Service/Event/Archive/SlugAlreadyExistsException.php src/Entity/EventNotificationSubscription.php
git commit -m "101 - add EventArchiveExporter (event -> zip) and subscription getters"
```

---

### Task 4: `EventArchiveImporter` + roundtrip integration test

Reads a ZIP, validates the manifest, refuses a colliding slug, then recreates the event, photos (as `Ready` from bundled derivatives), and subscribers (reconstituted, tokens regenerated) in one transaction with file-cleanup on failure.

**Files:**
- Create: `src/Service/Event/EventArchiveImporter.php`
- Test: `tests/Integration/Service/Event/EventArchiveRoundtripTest.php`

**Interfaces:**
- Consumes: `EventArchiveExporter::export()`, `EventArchiveManifest::fromJson()`, `EventNotificationSubscription::reconstituteForImport()`, `SlugAlreadyExistsException`, `InvalidArchiveException`, `EventRepository::findOneBySlug()`.
- Produces: `EventArchiveImporter::import(string $zipPath, User $owner): Event`

- [ ] **Step 1: Write the failing roundtrip test**

Create `tests/Integration/Service/Event/EventArchiveRoundtripTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Event;

use App\Entity\Event;
use App\Entity\EventNotificationStatus;
use App\Entity\EventNotificationSubscription;
use App\Entity\Photo;
use App\Entity\User;
use App\Repository\EventNotificationSubscriptionRepository;
use App\Repository\PhotoRepository;
use App\Service\Event\Archive\SlugAlreadyExistsException;
use App\Service\Event\EventArchiveExporter;
use App\Service\Event\EventArchiveImporter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EventArchiveRoundtripTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private EventArchiveExporter $exporter;

    private EventArchiveImporter $importer;

    private FilesystemOperator $thumbs;

    private FilesystemOperator $previews;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $this->em       = $c->get(EntityManagerInterface::class);
        $this->exporter = $c->get(EventArchiveExporter::class);
        $this->importer = $c->get(EventArchiveImporter::class);
        $this->thumbs   = $c->get('photo_thumbs_storage');
        $this->previews = $c->get('photo_previews_storage');
    }

    public function testExportThenImportRecreatesEventUnderNewOwner(): void
    {
        $utc    = new DateTimeZone('UTC');
        $source = $this->makeUser('src@example.com');
        $target = $this->makeUser('dst@example.com');

        $event = new Event(
            'roundtrip-src',
            'Roundtrip',
            new DateTimeImmutable('2026-03-01 10:00:00', $utc),
            new DateTimeImmutable('2026-03-01 12:00:00', $utc),
            $source,
        );
        $event->markPublished(new DateTimeImmutable('2026-03-01 13:00:00', $utc));
        $event->enableNotifications();
        $this->em->persist($event);
        $this->em->flush();

        $photo = new Photo($event, str_repeat('b', 64), 'IMG_1.jpg', 111);
        $photo->markReady(new DateTimeImmutable('2026-03-01 11:00:00', $utc), 4000, 3000, 200_000);
        $this->em->persist($photo);
        $this->em->flush();

        $path = sprintf('event-%d/%d.jpg', (int) $event->getId(), (int) $photo->getId());
        $this->thumbs->write($path, 'THUMBBYTES');
        $this->previews->write($path, 'PREVIEWBYTES');

        $srcSub = EventNotificationSubscription::reconstituteForImport(
            $event,
            'fan@example.com',
            EventNotificationStatus::Confirmed,
            new DateTimeImmutable('2026-02-01 09:00:00', $utc),
            new DateTimeImmutable('2026-02-01 09:05:00', $utc),
            null,
            null,
        );
        $this->em->persist($srcSub);
        $this->em->flush();
        $srcToken = $srcSub->getUnsubscribeToken();

        // Export, then rename the source so the slug is free for import.
        $zip = $this->exporter->export($event);
        $event->setSlug('roundtrip-src-archived');
        $this->em->flush();

        $imported = $this->importer->import($zip, $target);
        @unlink($zip);

        self::assertSame('roundtrip-src', $imported->getSlug());
        self::assertSame($target, $imported->getOwner());
        self::assertTrue($imported->isPublished());
        self::assertTrue($imported->areNotificationsEnabled());

        /** @var PhotoRepository $photos */
        $photos = self::getContainer()->get(PhotoRepository::class);
        self::assertSame(1, $photos->countReady($imported));

        $importedPath = sprintf(
            'event-%d/%d.jpg',
            (int) $imported->getId(),
            (int) $photos->findReadyInWindow(
                $imported,
                new DateTimeImmutable('2026-03-01 00:00:00', $utc),
                new DateTimeImmutable('2026-03-02 00:00:00', $utc),
            )[0]->getId(),
        );
        self::assertSame('THUMBBYTES', $this->thumbs->read($importedPath));
        self::assertSame('PREVIEWBYTES', $this->previews->read($importedPath));

        /** @var EventNotificationSubscriptionRepository $subs */
        $subs         = self::getContainer()->get(EventNotificationSubscriptionRepository::class);
        $importedSub  = $subs->findOneByEventAndEmail($imported, 'fan@example.com');
        self::assertInstanceOf(EventNotificationSubscription::class, $importedSub);
        self::assertSame(EventNotificationStatus::Confirmed, $importedSub->getStatus());
        self::assertNotSame($srcToken, $importedSub->getUnsubscribeToken(), 'tokens must be regenerated');
    }

    public function testImportRefusesCollidingSlug(): void
    {
        $utc   = new DateTimeZone('UTC');
        $owner = $this->makeUser('coll@example.com');

        $event = new Event(
            'collide',
            'Collide',
            new DateTimeImmutable('2026-03-01 10:00:00', $utc),
            new DateTimeImmutable('2026-03-01 12:00:00', $utc),
            $owner,
        );
        $this->em->persist($event);
        $this->em->flush();

        $zip = $this->exporter->export($event);

        $this->expectException(SlugAlreadyExistsException::class);
        try {
            $this->importer->import($zip, $owner);
        } finally {
            @unlink($zip);
        }
    }

    private function makeUser(string $email): User
    {
        $user = new User($email, 'Name');
        $user->addRole('ROLE_ORGANIZER');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `vendor/bin/phpunit tests/Integration/Service/Event/EventArchiveRoundtripTest.php`
Expected: FAIL — `EventArchiveImporter` not found / not a service.

- [ ] **Step 3: Implement the importer**

`src/Service/Event/EventArchiveImporter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Event;

use App\Entity\Event;
use App\Entity\EventNotificationStatus;
use App\Entity\EventNotificationSubscription;
use App\Entity\Photo;
use App\Entity\User;
use App\Repository\EventRepository;
use App\Service\Event\Archive\EventArchiveManifest;
use App\Service\Event\Archive\InvalidArchiveException;
use App\Service\Event\Archive\ManifestPhoto;
use App\Service\Event\Archive\ManifestSubscription;
use App\Service\Event\Archive\SlugAlreadyExistsException;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Random\RandomException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;
use ZipArchive;

final readonly class EventArchiveImporter
{
    private const int LOGO_NAME_BYTES = 16;

    private const string JPEG_SOI = "\xFF\xD8";

    public function __construct(
        private EntityManagerInterface $em,
        private EventRepository $events,
        #[Autowire(service: 'photo_thumbs_storage')]
        private FilesystemOperator $thumbs,
        #[Autowire(service: 'photo_previews_storage')]
        private FilesystemOperator $previews,
        #[Autowire(service: 'event_logos_storage')]
        private FilesystemOperator $logos,
    ) {
    }

    /**
     * @throws InvalidArchiveException
     * @throws SlugAlreadyExistsException
     */
    public function import(string $zipPath, User $owner): Event
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new InvalidArchiveException('Upload is not a readable ZIP archive.');
        }

        try {
            $manifestJson = $zip->getFromName('manifest.json');
            if ($manifestJson === false) {
                throw new InvalidArchiveException('Archive is missing manifest.json.');
            }

            $manifest = EventArchiveManifest::fromJson($manifestJson);

            if ($this->events->findOneBySlug($manifest->event->slug) instanceof Event) {
                throw new SlugAlreadyExistsException($manifest->event->slug);
            }

            return $this->reconstitute($manifest, $zip, $owner);
        } finally {
            $zip->close();
        }
    }

    /**
     * @throws InvalidArchiveException
     */
    private function reconstitute(EventArchiveManifest $manifest, ZipArchive $zip, User $owner): Event
    {
        $utc   = new DateTimeZone('UTC');
        $me    = $manifest->event;
        $event = new Event(
            $me->slug,
            $me->name,
            new DateTimeImmutable($me->startsAt),
            new DateTimeImmutable($me->endsAt),
            $owner,
        );
        $event->setDescription($me->description);
        $event->setTimezone($me->timezone);
        $event->getStyle()->setFontColor($me->fontColor);
        $event->getStyle()->setBackgroundColor($me->backgroundColor);
        $event->getStyle()->setButtonColor($me->buttonColor);
        $event->getStyle()->setGlowEnabled($me->glowEnabled);

        if ($me->publishedAt !== null) {
            $event->markPublished(new DateTimeImmutable($me->publishedAt));
        }

        if ($me->notificationsEnabled) {
            $event->enableNotifications();
        }

        /** @var list<array{FilesystemOperator, string}> $written */
        $written = [];

        $this->em->beginTransaction();

        try {
            $this->em->persist($event);
            $this->em->flush(); // assigns the event id

            if ($me->logoFilename !== null) {
                $written[] = [$this->logos, $this->writeLogo($zip, $me->logoFilename)];
                $event->setLogoFilename($written[array_key_last($written)][1]);
            }

            foreach ($manifest->photos as $manifestPhoto) {
                $written = array_merge($written, $this->reconstitutePhoto($event, $manifestPhoto, $zip));
            }

            foreach ($manifest->subscriptions as $manifestSub) {
                $this->em->persist($this->reconstituteSubscription($event, $manifestSub, $utc));
            }

            $this->em->flush();
            $this->em->commit();

            return $event;
        } catch (Throwable $e) {
            $this->em->rollback();
            foreach ($written as [$storage, $path]) {
                try {
                    $storage->delete($path);
                } catch (Throwable) {
                    // best-effort cleanup
                }
            }

            throw $e;
        }
    }

    /**
     * @return list<array{FilesystemOperator, string}>
     * @throws InvalidArchiveException
     */
    private function reconstitutePhoto(Event $event, ManifestPhoto $mp, ZipArchive $zip): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $mp->contentHash) !== 1) {
            throw new InvalidArchiveException('Photo content hash is malformed.');
        }

        $thumbBytes   = $this->readJpeg($zip, 'photos/' . $mp->contentHash . '.thumb.jpg');
        $previewBytes = $this->readJpeg($zip, 'photos/' . $mp->contentHash . '.preview.jpg');

        $photo = new Photo($event, $mp->contentHash, $mp->originalFilename, $mp->byteSize);
        $takenAt = $mp->takenAt !== null
            ? new DateTimeImmutable($mp->takenAt)
            : new DateTimeImmutable($mp->createdAt);
        $photo->markReady($takenAt, $mp->width, $mp->height, $mp->derivativeBytes);

        $this->em->persist($photo);
        $this->em->flush(); // assigns the photo id for the storage path

        $path = sprintf('event-%d/%d.jpg', (int) $event->getId(), (int) $photo->getId());
        $this->thumbs->write($path, $thumbBytes);
        $this->previews->write($path, $previewBytes);

        return [[$this->thumbs, $path], [$this->previews, $path]];
    }

    private function reconstituteSubscription(
        Event $event,
        ManifestSubscription $ms,
        DateTimeZone $utc,
    ): EventNotificationSubscription {
        $status = EventNotificationStatus::tryFrom($ms->status) ?? EventNotificationStatus::Pending;

        return EventNotificationSubscription::reconstituteForImport(
            $event,
            $ms->email,
            $status,
            new DateTimeImmutable($ms->createdAt, $utc),
            $ms->confirmedAt !== null ? new DateTimeImmutable($ms->confirmedAt, $utc) : null,
            $ms->unsubscribedAt !== null ? new DateTimeImmutable($ms->unsubscribedAt, $utc) : null,
            $ms->notifiedAt !== null ? new DateTimeImmutable($ms->notifiedAt, $utc) : null,
        );
    }

    /**
     * @throws InvalidArchiveException
     */
    private function writeLogo(ZipArchive $zip, string $originalFilename): string
    {
        $bytes = $zip->getFromName('images/logo/' . basename($originalFilename));
        if ($bytes === false) {
            throw new InvalidArchiveException('Archive manifest references a logo that is missing.');
        }

        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
            $ext = 'png';
        }

        try {
            $name = bin2hex(random_bytes(self::LOGO_NAME_BYTES)) . '.' . $ext;
        } catch (RandomException $e) {
            throw new InvalidArchiveException('Could not generate a logo filename.', 0, $e);
        }

        $this->logos->write($name, $bytes);

        return $name;
    }

    /**
     * @throws InvalidArchiveException
     */
    private function readJpeg(ZipArchive $zip, string $entry): string
    {
        $bytes = $zip->getFromName($entry);
        if ($bytes === false || !str_starts_with($bytes, self::JPEG_SOI)) {
            throw new InvalidArchiveException(sprintf('Archive entry "%s" is missing or not a JPEG.', $entry));
        }

        return $bytes;
    }
}
```

- [ ] **Step 4: Run the test to confirm it passes**

Run: `vendor/bin/phpunit tests/Integration/Service/Event/EventArchiveRoundtripTest.php`
Expected: PASS (2 tests). If the roundtrip photo lookup is flaky on ordering, it is fine to assert on `countReady` + subscriber only; the derivative-bytes assertion is the important one.

- [ ] **Step 5: Static analysis + commit**

Run: `vendor/bin/phpstan analyse src/Service/Event/EventArchiveImporter.php`
Expected: no errors.

```bash
git add src/Service/Event/EventArchiveImporter.php tests/Integration/Service/Event/EventArchiveRoundtripTest.php
git commit -m "101 - add EventArchiveImporter with transactional reconstitution + roundtrip test"
```

---

### Task 5: Export controller action + audit + index button

Wire the exporter to `GET /admin/events/{id}/export`, gated by `EventVoter::VIEW`, audited, streaming the temp ZIP as an attachment. Add the two `AuditAction` cases and an Export link in the index.

**Files:**
- Modify: `src/Audit/AuditAction.php`, `src/Controller/Admin/EventController.php`, `templates/admin/event/index.html.twig`
- Test: `tests/Functional/Admin/EventExportTest.php`

**Interfaces:**
- Consumes: `EventArchiveExporter::export()`, `AuditAction::EventExport`.
- Produces: route `admin_event_export` at `/admin/events/{id}/export`.

- [ ] **Step 1: Write the failing functional test**

Create `tests/Functional/Admin/EventExportTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EventExportTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testOwnerDownloadsZipAttachment(): void
    {
        [$owner, $event] = $this->makeOwnerAndEvent('exp-owner');
        $this->client->loginUser($owner);

        $this->client->request(Request::METHOD_GET, '/admin/events/' . $event->getId() . '/export');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/zip');
        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertStringContainsString('attachment', $disposition);
        self::assertStringContainsString('event-exp-owner.zip', $disposition);
    }

    public function testNonOwnerIsDenied(): void
    {
        [, $event] = $this->makeOwnerAndEvent('exp-denied');

        $stranger = new User('stranger@example.com', 'Stranger');
        $stranger->addRole('ROLE_ORGANIZER');
        $this->em->persist($stranger);
        $this->em->flush();
        $this->client->loginUser($stranger);

        $this->client->request(Request::METHOD_GET, '/admin/events/' . $event->getId() . '/export');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return array{0: User, 1: Event}
     */
    private function makeOwnerAndEvent(string $slug): array
    {
        $owner = new User($slug . '@example.com', 'Owner');
        $owner->addRole('ROLE_ORGANIZER');
        $this->em->persist($owner);

        $event = new Event(
            $slug,
            'Event',
            new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-01-01 12:00:00', new DateTimeZone('UTC')),
            $owner,
        );
        $this->em->persist($event);
        $this->em->flush();

        return [$owner, $event];
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `vendor/bin/phpunit tests/Functional/Admin/EventExportTest.php`
Expected: FAIL — 404 (no route yet).

- [ ] **Step 3: Add the audit cases**

In `src/Audit/AuditAction.php`, add after `case EventNotificationsToggle = 'event.notifications_toggle';`:

```php
    case EventExport = 'event.export';
    case EventImport = 'event.import';
```

(`category()` already maps `event.*` → "Event"; no other change.)

- [ ] **Step 4: Add the controller dependency + action**

In `src/Controller/Admin/EventController.php`, add the import at the top:

```php
use App\Service\Event\EventArchiveExporter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
```

Add to the constructor parameter list:

```php
        private readonly EventArchiveExporter $exporter,
```

Add this action (after `logo()`), keeping the `#[Audited]` pattern:

```php
    #[Route(
        '/admin/events/{id}/export',
        name: 'admin_event_export',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    #[Audited(AuditAction::EventExport, targetParam: 'id', targetType: 'Event')]
    public function export(Event $event): BinaryFileResponse
    {
        $this->denyAccessUnlessGranted(EventVoter::VIEW, $event);

        $this->audit->targetLabel($event->getName() . ' (' . $event->getSlug() . ')');

        $response = new BinaryFileResponse($this->exporter->export($event));
        $response->headers->set('Content-Type', 'application/zip');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('event-%s.zip', $event->getSlug()),
        );
        $response->deleteFileAfterSend(true);

        return $response;
    }
```

- [ ] **Step 5: Add the Export link to the index template**

In `templates/admin/event/index.html.twig`, in the per-event actions cell (next to the existing edit/QR links — match the surrounding markup), add:

```twig
<a href="{{ path('admin_event_export', {id: event.id}) }}">Export</a>
```

- [ ] **Step 6: Run the test to confirm it passes**

Run: `vendor/bin/phpunit tests/Functional/Admin/EventExportTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add src/Audit/AuditAction.php src/Controller/Admin/EventController.php templates/admin/event/index.html.twig tests/Functional/Admin/EventExportTest.php
git commit -m "101 - add event export action, route, audit cases and index link"
```

---

### Task 6: Import controller action + form + template

Add `GET/POST /admin/events/import` with a non-entity upload form (admin owner selector), calling the importer and translating exceptions to flashes.

**Files:**
- Create: `src/Form/EventImportType.php`, `templates/admin/event/import.html.twig`
- Modify: `src/Controller/Admin/EventController.php`, `templates/admin/event/index.html.twig`
- Test: `tests/Functional/Admin/EventImportTest.php`

**Interfaces:**
- Consumes: `EventArchiveImporter::import()`, `EventArchiveExporter::export()` (test builds a real archive), `SlugAlreadyExistsException`, `InvalidArchiveException`.
- Produces: route `admin_event_import` at `/admin/events/import`.

- [ ] **Step 1: Write the failing functional test**

Create `tests/Functional/Admin/EventImportTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\User;
use App\Service\Event\EventArchiveExporter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class EventImportTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testOrganizerImportsUnderThemselves(): void
    {
        $owner = $this->makeOrganizer('imp-owner@example.com');
        $zip   = $this->buildArchive('imported-slug', $owner);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/events/import');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Import')->form();
        $form['event_import[archive]']->upload(new UploadedFile($zip, 'archive.zip', 'application/zip', null, true));
        $this->client->submit($form);

        self::assertResponseRedirects();
        $imported = $this->em->getRepository(Event::class)->findOneBy(['slug' => 'imported-slug']);
        self::assertInstanceOf(Event::class, $imported);
        self::assertSame($owner->getId(), $imported->getOwner()->getId());
    }

    public function testCollidingSlugIsRefusedAndCreatesNothing(): void
    {
        $owner = $this->makeOrganizer('imp-collide@example.com');
        $this->buildEvent('dupe-slug', $owner); // already exists
        $zip = $this->buildArchive('dupe-slug', $owner, persist: false);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/events/import');
        $form    = $crawler->selectButton('Import')->form();
        $form['event_import[archive]']->upload(new UploadedFile($zip, 'archive.zip', 'application/zip', null, true));
        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertSame(1, $this->em->getRepository(Event::class)->count(['slug' => 'dupe-slug']));
    }

    public function testAnonymousIsDenied(): void
    {
        $this->client->request(Request::METHOD_GET, '/admin/events/import');
        self::assertResponseStatusCodeSame(302); // redirected to login
    }

    private function buildArchive(string $slug, User $owner, bool $persist = true): string
    {
        $event = $this->buildEvent($slug, $owner, $persist);
        /** @var EventArchiveExporter $exporter */
        $exporter = self::getContainer()->get(EventArchiveExporter::class);
        $zip      = $exporter->export($event);

        if ($persist) {
            // Free the slug so the archive can be imported back.
            $event->setSlug($slug . '-archived');
            $this->em->flush();
        } else {
            $this->em->remove($event);
            $this->em->flush();
        }

        return $zip;
    }

    private function buildEvent(string $slug, User $owner, bool $persist = true): Event
    {
        $event = new Event(
            $slug,
            'Event ' . $slug,
            new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-01-01 12:00:00', new DateTimeZone('UTC')),
            $owner,
        );

        if ($persist) {
            $this->em->persist($event);
            $this->em->flush();
        }

        return $event;
    }

    private function makeOrganizer(string $email): User
    {
        $user = new User($email, 'Owner');
        $user->addRole('ROLE_ORGANIZER');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `vendor/bin/phpunit tests/Functional/Admin/EventImportTest.php`
Expected: FAIL — 404 (no route yet).

- [ ] **Step 3: Create the form**

`src/Form/EventImportType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class EventImportType extends AbstractType
{
    private const string MAX_UPLOAD = '256M';

    public function __construct(private readonly Security $security)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('archive', FileType::class, [
            'label'       => 'Event archive (.zip)',
            'mapped'      => false,
            'constraints' => [
                new Assert\NotNull(message: 'Choose an archive to import.'),
                new Assert\File(
                    maxSize: self::MAX_UPLOAD,
                    mimeTypes: ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
                    mimeTypesMessage: 'Upload the .zip produced by Export.',
                ),
            ],
        ]);

        if ($this->security->isGranted('ROLE_ADMIN')) {
            $builder->add('owner', EntityType::class, [
                'class'        => User::class,
                'choice_label' => 'email',
                'mapped'       => false,
                'required'     => false,
                'placeholder'  => '— import under me —',
                'label'        => 'Assign to user (admin)',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
```

- [ ] **Step 4: Create the template**

`templates/admin/event/import.html.twig` (extend whatever the other admin templates extend — match `edit`/`form`):

```twig
{% extends 'admin/_base.html.twig' %}

{% block body %}
    <h1>Import event</h1>

    {{ form_start(form) }}
        {{ form_row(form.archive) }}
        {% if form.owner is defined %}
            {{ form_row(form.owner) }}
        {% endif %}
        <button type="submit">Import</button>
    {{ form_end(form) }}
{% endblock %}
```

> If `admin/_base.html.twig` uses a different block name than `body`, mirror the block name used by `templates/admin/event/form.html.twig`.

- [ ] **Step 5: Add the import action**

In `src/Controller/Admin/EventController.php` add imports:

```php
use App\Form\EventImportType;
use App\Service\Event\EventArchiveImporter;
use App\Service\Event\Archive\InvalidArchiveException;
use App\Service\Event\Archive\SlugAlreadyExistsException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
```

Add to the constructor:

```php
        private readonly EventArchiveImporter $importer,
```

Add the action. **Place it before `edit()`** so the literal `/admin/events/import` path is matched before the `/{id}` route (Symfony matches in declaration order; `import` is non-numeric so `{id}` with `\d+` wouldn't capture it, but declaring it early avoids any ambiguity):

```php
    #[Route('/admin/events/import', name: 'admin_event_import', methods: ['GET', 'POST'])]
    #[Audited(AuditAction::EventImport, targetParam: null)]
    public function import(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(EventImportType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $archive = $form->get('archive')->getData();
            $owner   = $form->has('owner') && $form->get('owner')->getData() instanceof User
                ? $form->get('owner')->getData()
                : $user;

            if ($archive instanceof UploadedFile) {
                try {
                    $event = $this->importer->import($archive->getPathname(), $owner);

                    $this->audit->set('created_id', $event->getId());
                    $this->audit->targetLabel($event->getName() . ' (' . $event->getSlug() . ')');
                    $this->addFlash('success', sprintf('Imported event "%s".', $event->getName()));

                    return $this->redirectToRoute('admin_event_edit', ['id' => $event->getId()]);
                } catch (SlugAlreadyExistsException $e) {
                    $this->addFlash('error', sprintf('An event with slug "%s" already exists — import refused.', $e->slug));
                } catch (InvalidArchiveException $e) {
                    $this->addFlash('error', 'Invalid archive: ' . $e->getMessage());
                }
            }
        }

        return $this->render('admin/event/import.html.twig', ['form' => $form]);
    }
```

- [ ] **Step 6: Add the Import link to the index template**

In `templates/admin/event/index.html.twig`, near the "New event" action at the top, add:

```twig
<a href="{{ path('admin_event_import') }}">Import event</a>
```

- [ ] **Step 7: Run the test to confirm it passes**

Run: `vendor/bin/phpunit tests/Functional/Admin/EventImportTest.php`
Expected: PASS (3 tests).

- [ ] **Step 8: Commit**

```bash
git add src/Form/EventImportType.php templates/admin/event/import.html.twig templates/admin/event/index.html.twig src/Controller/Admin/EventController.php tests/Functional/Admin/EventImportTest.php
git commit -m "101 - add event import action, upload form, admin owner selector and template"
```

---

### Task 7: Full-suite green + GrumPHP gate

**Files:** none (verification only).

- [ ] **Step 1: Run the feature's tests together**

Run:
```bash
vendor/bin/phpunit tests/Unit/Entity/EventNotificationSubscriptionReconstituteTest.php \
  tests/Unit/Service/Event/Archive/EventArchiveManifestTest.php \
  tests/Integration/Service/Event/EventArchiveRoundtripTest.php \
  tests/Functional/Admin/EventExportTest.php \
  tests/Functional/Admin/EventImportTest.php
```
Expected: all PASS.

- [ ] **Step 2: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: green (no deprecations/notices/warnings — PHPUnit fails on those).

- [ ] **Step 3: Run the quality gate**

Run: `vendor/bin/grumphp run`
Expected: phpstan L10, phpcs PSR-12, phpmnd, phpcpd, rector, securitychecker, and `doctrine:schema:validate --skip-sync` all pass.
Fix anything flagged (common: phpmnd wants a named constant; phpcs import ordering; rector suggestions). Re-run until green.

- [ ] **Step 4: Propose the final commit / PR**

Do not auto-commit. Summarise the branch and propose a single-line message, e.g.:
`101 - event export/import: portable event archive (settings, photos, subscribers) with admin owner selector and slug-collision guard - closes #101`

## Verification (end-to-end, manual)

1. `bin/console app:create-user admin@example.com "Admin" secret ROLE_ADMIN` (or use an existing organizer).
2. Log in, create an event, upload a JPEG, wait for the worker to mark it Ready.
3. Events list → **Export** → a `event-<slug>.zip` downloads. Unzip and confirm `manifest.json`, `photos/<hash>.thumb.jpg`, `photos/<hash>.preview.jpg`.
4. Delete or rename the source event (free the slug). Events list → **Import event** → upload the zip. As an admin, pick a target user; as an organizer, leave blank.
5. The new event opens in edit; its photos serve at `/e/<slug>/p/<id>/thumb.jpg`; subscriber count matches; publish state matches the source.
6. Re-import the same zip without freeing the slug → refused with a flash naming the slug; event count unchanged.

## Known limitations (documented, out of scope)

- **Upload size:** the VPS nginx caps request bodies at 32 MB (`client_max_body_size`), so importing a very large archive over HTTP will 413 there even though the form allows 256 MB. Fine for typical events; raising the cap or async upload is a follow-up.
- **Banner** image is not exported (feature #93 not on `main`).
- **Photo `createdAt`** is reset to import time (the entity assigns it in its constructor); `takenAt` — which drives gallery ordering — is preserved.

## Self-Review notes

- **Spec coverage:** photo payload (Task 3/4), subscriptions + token regen (Task 1/4), publish preserve (Task 4 test), sync download (Task 5), slug refuse (Task 4/6), only-Ready + skippedPhotos (Task 3), audit cases (Task 5), no migration (confirmed). ✔
- **Type consistency:** `reconstituteForImport` signature identical in Task 1, 4; manifest DTO field names identical across Task 2 → 3 → 4; storage path template `event-<id>/<id>.jpg` identical in exporter/importer. ✔
- **phpmnd:** named constants `LOGO_NAME_BYTES`, `JPEG_SOI`, `TMP_PREFIX`, `MAX_UPLOAD`, `VERSION`. ✔
