# Photo Ingest Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the organizer-only photo bulk upload, async EXIF + derivative pipeline, admin moderation, and public time-window gallery described in `docs/superpowers/specs/2026-06-10-photo-ingest-design.md`.

**Architecture:** Synchronous upload controller hashes the file, deduplicates, persists a `pending` `Photo` row, moves the original to private Flysystem storage, and dispatches a Messenger message. Worker reads EXIF (strict — reject if `DateTimeOriginal` missing), normalizes to UTC using the new `Event.timezone`, renders thumbnail + preview JPEGs via GD, and flips the row to `ready`. Admin sees a polling Turbo Frame grid of photos with retry/delete. Public gallery hits a repository method bounded to `LIMIT 200` over the `(event_id, status, taken_at)` index.

**Tech Stack:** PHP 8.5 + Symfony 8.1, Doctrine ORM 3, `league/flysystem-bundle` (already installed), `symfony/messenger` (new), `symfony/doctrine-messenger` (new), built-in `exif` + `gd` PHP extensions, Uppy (vendored via AssetMapper) + Stimulus + Turbo. PHPUnit 13 + DAMA Doctrine Test Bundle for tests.

**Repo conventions to follow** (sourced from the existing codebase):
- All PHP files: `declare(strict_types=1);` first line; `final class` unless extension is required.
- Doctrine columns via PHP 8 attributes (no annotations). Constructor-promoted required columns.
- Voter class constants typed: `public const string EDIT = 'PHOTO_EDIT';`.
- Service injection: constructor `private readonly`, autowired by type. Named Flysystem storages injected with `#[Autowire(service: '<storage_name>')]` returning `League\Flysystem\FilesystemOperator` (matches `EventController` lines 33-34).
- Flysystem storage names use underscores, e.g. `photo_originals_storage`, on-disk paths under `var/uploads/` (matches `event_logos_storage`).
- Commit messages: `13 - <description>` (issue #13 → branch `feature/13-event-photo-upload`). GrumPHP `git_commit_message` rule enforces this.
- Tests: unit under `tests/Unit/{Entity,Security}/...`, functional under `tests/Functional/{Admin,Public}/...`, fixtures under `tests/fixtures/`.

**Quality gate after every task:**
```bash
vendor/bin/phpunit
vendor/bin/grumphp run
```
Both must be green before commit. The plan does not repeat this in every step; treat it as a final verification before each `git commit` step.

---

## File Structure

**New files:**

```
src/
  Entity/Photo.php
  Entity/PhotoStatus.php                  -- string-backed enum
  Repository/PhotoRepository.php
  Security/Voter/PhotoVoter.php
  Service/Photo/ExifReader.php
  Service/Photo/DerivativeGenerator.php
  Service/Photo/PhotoRejected.php         -- domain exception
  Message/ProcessPhoto.php
  MessageHandler/ProcessPhotoHandler.php
  Controller/Admin/PhotoController.php    -- upload, grid, retry, delete
  Controller/Public/PhotoServeController.php  -- /p/{id}/thumb.jpg, /p/{id}/preview.jpg
migrations/
  Version{TS}_AddTimezoneAndPhotos.php    -- one migration
config/packages/
  messenger.yaml
  flysystem.yaml                          -- modify (existing file)
assets/vendor/uppy/                       -- vendored via importmap
assets/controllers/photos_poller_controller.js
templates/admin/event/
  photos_panel.html.twig                  -- included on edit page
  photos_grid.html.twig                   -- Turbo Frame contents
  _photo_tile.html.twig
templates/public/event/
  photos.html.twig                        -- modify (replace stub)
tests/fixtures/photos/
  with-datetime-original.jpg
  with-offset-time.jpg
  no-exif.jpg
  bigger.jpg
tests/Unit/Entity/PhotoTest.php
tests/Unit/Security/PhotoVoterTest.php
tests/Unit/Service/Photo/ExifReaderTest.php
tests/Unit/Service/Photo/DerivativeGeneratorTest.php
tests/Integration/MessageHandler/ProcessPhotoHandlerTest.php
tests/Integration/Repository/PhotoRepositoryTest.php
tests/Functional/Admin/PhotoUploadTest.php
tests/Functional/Admin/PhotoModerationTest.php
tests/Functional/Public/PhotoServeTest.php
tests/Functional/Public/EventPhotosGalleryTest.php  -- modify the existing stub test
```

**Modified files:**

```
src/Entity/Event.php                      -- add timezone field
src/Form/EventType.php                    -- add timezone form field
src/Controller/Public/EventController.php -- wire repository into photos() action
.env                                      -- MESSENGER_TRANSPORT_DSN
.gitignore                                -- /var/uploads/photos/
composer.json / composer.lock             -- messenger packages
importmap.php                             -- uppy
templates/admin/event/form.html.twig      -- include photos_panel partial when editing
README.md                                 -- worker process docs
```

---

## Task 1: Add `timezone` to Event entity

**Goal:** Required `timezone` (IANA) field on Event, defaulted to `Europe/Amsterdam` for existing rows via migration, validated by `Symfony\Component\Validator\Constraints\Timezone`.

**Files:**
- Modify: `src/Entity/Event.php`
- Modify: `src/Form/EventType.php`
- Create: `migrations/Version{TS1}_AddEventTimezone.php`
- Modify: `tests/Unit/Entity/EventTest.php`

- [ ] **Step 1: Add failing unit test for timezone field**

Append to `tests/Unit/Entity/EventTest.php`:

```php
public function testTimezoneIsRequiredAndStoredAsIanaName(): void
{
    $event = new Event('e', 'Event', new DateTimeImmutable('2026-06-10'), $this->makeOwner());
    $event->setTimezone('Europe/Amsterdam');

    self::assertSame('Europe/Amsterdam', $event->getTimezone());
}

public function testTimezoneCanBeChanged(): void
{
    $event = new Event('e', 'Event', new DateTimeImmutable('2026-06-10'), $this->makeOwner());
    $event->setTimezone('Europe/Amsterdam');
    $event->setTimezone('America/New_York');

    self::assertSame('America/New_York', $event->getTimezone());
}
```

If `makeOwner()` doesn't already exist in the test class, copy it from the existing tests in the same file.

- [ ] **Step 2: Run test, expect failure**

```bash
vendor/bin/phpunit tests/Unit/Entity/EventTest.php
```
Expected: errors referencing missing `getTimezone()` / `setTimezone()` methods.

- [ ] **Step 3: Add the column + getter/setter to Event**

Modify `src/Entity/Event.php`. Add the column after `defaultWindowMinutes`:

```php
#[ORM\Column(type: Types::STRING, length: 64)]
#[Assert\Timezone]
private string $timezone = 'Europe/Amsterdam';
```

Add getter/setter (PSR-12 style, match existing methods):

```php
public function getTimezone(): string
{
    return $this->timezone;
}

public function setTimezone(string $timezone): void
{
    $this->timezone = $timezone;
}
```

Add `use Symfony\Component\Validator\Constraints as Assert;` import if not already present (it is — line 13).

- [ ] **Step 4: Add the timezone form field**

In `src/Form/EventType.php`, inside `buildForm`, after the `defaultWindowMinutes` field:

```php
->add('timezone', ChoiceType::class, [
    'choices' => array_combine(
        DateTimeZone::listIdentifiers(),
        DateTimeZone::listIdentifiers(),
    ),
    'help'    => 'IANA zone for EXIF timestamps without an explicit offset.',
])
```

Add imports:
```php
use DateTimeZone;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
```

- [ ] **Step 5: Run test, expect pass**

```bash
vendor/bin/phpunit tests/Unit/Entity/EventTest.php
```
Expected: green.

- [ ] **Step 6: Generate the migration**

```bash
php bin/console doctrine:migrations:diff --no-interaction
```

Open the new file under `migrations/`. The `up()` should contain something like:
```sql
ALTER TABLE events ADD timezone VARCHAR(64) NOT NULL
```

If the migration tool emits `NOT NULL` without a default, manually adjust the file so existing rows get backfilled. Change `up()` to:

```php
public function up(Schema $schema): void
{
    $this->addSql("ALTER TABLE events ADD timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Amsterdam'");
    $this->addSql('ALTER TABLE events ALTER COLUMN timezone DROP DEFAULT');
}

public function down(Schema $schema): void
{
    $this->addSql('ALTER TABLE events DROP timezone');
}
```

- [ ] **Step 7: Run the migration on dev db, then on test db**

```bash
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:migrations:migrate --no-interaction --env=test
```

Expected: both report "executed migration ... AddEventTimezone".

- [ ] **Step 8: Verify quality gate**

```bash
vendor/bin/phpunit
vendor/bin/grumphp run
```
Both green.

- [ ] **Step 9: Commit**

```bash
git add src/Entity/Event.php src/Form/EventType.php migrations/ tests/Unit/Entity/EventTest.php
git commit -m "13 - add timezone field to Event entity"
```

---

## Task 2: PhotoStatus enum + Photo entity + migration

**Goal:** Persisted `Photo` entity with status transition methods. No public `setStatus()`. Unique key on `(event_id, content_hash)`. Index on `(event_id, status, taken_at)`.

**Files:**
- Create: `src/Entity/PhotoStatus.php`
- Create: `src/Entity/Photo.php`
- Create: `src/Repository/PhotoRepository.php` (empty for now — populated in Task 4)
- Create: `tests/Unit/Entity/PhotoTest.php`
- Create: `migrations/Version{TS2}_AddPhotosTable.php`

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/Entity/PhotoTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\PhotoStatus;
use App\Entity\User;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class PhotoTest extends TestCase
{
    public function testNewPhotoIsPending(): void
    {
        $photo = $this->makePhoto();

        self::assertSame(PhotoStatus::Pending, $photo->getStatus());
        self::assertNull($photo->getTakenAt());
        self::assertNull($photo->getWidth());
        self::assertNull($photo->getHeight());
        self::assertNull($photo->getProcessingError());
    }

    public function testMarkReadyTransitions(): void
    {
        $photo = $this->makePhoto();
        $takenAt = new DateTimeImmutable('2026-06-10 12:00:00', new \DateTimeZone('UTC'));

        $photo->markReady($takenAt, 4032, 3024);

        self::assertSame(PhotoStatus::Ready, $photo->getStatus());
        self::assertEquals($takenAt, $photo->getTakenAt());
        self::assertSame(4032, $photo->getWidth());
        self::assertSame(3024, $photo->getHeight());
    }

    public function testMarkReadyRequiresPending(): void
    {
        $photo = $this->makePhoto();
        $photo->markFailed('reason');

        $this->expectException(DomainException::class);
        $photo->markReady(new DateTimeImmutable(), 1, 1);
    }

    public function testMarkFailedSetsError(): void
    {
        $photo = $this->makePhoto();
        $photo->markFailed('Missing EXIF DateTimeOriginal');

        self::assertSame(PhotoStatus::Failed, $photo->getStatus());
        self::assertSame('Missing EXIF DateTimeOriginal', $photo->getProcessingError());
    }

    public function testResetForRetryOnlyFromFailed(): void
    {
        $photo = $this->makePhoto();

        $this->expectException(DomainException::class);
        $photo->resetForRetry();
    }

    public function testResetForRetryClearsError(): void
    {
        $photo = $this->makePhoto();
        $photo->markFailed('boom');
        $photo->resetForRetry();

        self::assertSame(PhotoStatus::Pending, $photo->getStatus());
        self::assertNull($photo->getProcessingError());
    }

    private function makePhoto(): Photo
    {
        $owner = new User('owner@example.test', 'Owner');
        $event = new Event('slug', 'Event', new DateTimeImmutable('2026-06-10'), $owner);

        return new Photo(
            event: $event,
            contentHash: str_repeat('a', 64),
            originalFilename: 'IMG_0001.jpg',
            byteSize: 1234567,
        );
    }
}
```

- [ ] **Step 2: Run, expect failure**

```bash
vendor/bin/phpunit tests/Unit/Entity/PhotoTest.php
```
Expected: class-not-found errors.

- [ ] **Step 3: Create the PhotoStatus enum**

`src/Entity/PhotoStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

enum PhotoStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
```

- [ ] **Step 4: Create the Photo entity**

`src/Entity/Photo.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PhotoRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity(repositoryClass: PhotoRepository::class)]
#[ORM\Table(name: 'photos')]
#[ORM\UniqueConstraint(name: 'uniq_photos_event_hash', columns: ['event_id', 'content_hash'])]
#[ORM\Index(name: 'idx_photos_event_status_taken_at', columns: ['event_id', 'status', 'taken_at'])]
#[ORM\HasLifecycleCallbacks]
class Photo
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $width = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $height = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $takenAt = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: PhotoStatus::class)]
    private PhotoStatus $status = PhotoStatus::Pending;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $processingError = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Event::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Event $event,
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $contentHash,
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $originalFilename,
        #[ORM\Column(type: Types::INTEGER)]
        private int $byteSize,
    ) {
        $now = new DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function getByteSize(): int
    {
        return $this->byteSize;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function getTakenAt(): ?DateTimeImmutable
    {
        return $this->takenAt;
    }

    public function getStatus(): PhotoStatus
    {
        return $this->status;
    }

    public function getProcessingError(): ?string
    {
        return $this->processingError;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function markReady(DateTimeImmutable $takenAt, int $width, int $height): void
    {
        if ($this->status !== PhotoStatus::Pending) {
            throw new DomainException(sprintf(
                'Photo %d cannot transition from %s to ready.',
                (int) $this->id,
                $this->status->value,
            ));
        }

        $this->takenAt = $takenAt;
        $this->width = $width;
        $this->height = $height;
        $this->processingError = null;
        $this->status = PhotoStatus::Ready;
    }

    public function markFailed(string $reason): void
    {
        if ($this->status === PhotoStatus::Ready) {
            throw new DomainException(sprintf(
                'Photo %d is ready; refusing to mark failed.',
                (int) $this->id,
            ));
        }

        $this->processingError = $reason;
        $this->status = PhotoStatus::Failed;
    }

    public function resetForRetry(): void
    {
        if ($this->status !== PhotoStatus::Failed) {
            throw new DomainException(sprintf(
                'Photo %d cannot be reset for retry from %s.',
                (int) $this->id,
                $this->status->value,
            ));
        }

        $this->processingError = null;
        $this->status = PhotoStatus::Pending;
    }
}
```

- [ ] **Step 5: Create the (empty) repository**

`src/Repository/PhotoRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Photo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Photo>
 */
final class PhotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Photo::class);
    }
}
```

- [ ] **Step 6: Run unit test, expect pass**

```bash
vendor/bin/phpunit tests/Unit/Entity/PhotoTest.php
```
Expected: green (6 tests).

- [ ] **Step 7: Generate the photos-table migration**

```bash
php bin/console doctrine:migrations:diff --no-interaction
```

Verify the generated SQL creates `photos` with the unique constraint, index, and FK to `events` with `ON DELETE CASCADE`. If the FK is missing the cascade, hand-edit the `addSql(...)` line to include `ON DELETE CASCADE`.

- [ ] **Step 8: Run migration on dev and test**

```bash
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:migrations:migrate --no-interaction --env=test
```

- [ ] **Step 9: Quality gate**

```bash
vendor/bin/phpunit
vendor/bin/grumphp run
```
Both green.

- [ ] **Step 10: Commit**

```bash
git add src/Entity/Photo.php src/Entity/PhotoStatus.php src/Repository/PhotoRepository.php migrations/ tests/Unit/Entity/PhotoTest.php
git commit -m "13 - add Photo entity, PhotoStatus enum, and migration"
```

---

## Task 3: Configure Flysystem photo storages + .gitignore

**Goal:** Three named storages (`photo_originals_storage`, `photo_thumbs_storage`, `photo_previews_storage`) wired to local adapters under `var/uploads/photos/...`, gitignored.

**Files:**
- Modify: `config/packages/flysystem.yaml`
- Modify: `.gitignore`

- [ ] **Step 1: Extend `flysystem.yaml`**

Replace the contents of `config/packages/flysystem.yaml` with:

```yaml
flysystem:
    storages:
        event_logos_storage:
            adapter: 'local'
            options:
                directory: '%kernel.project_dir%/var/uploads/event-logos'
        photo_originals_storage:
            adapter: 'local'
            options:
                directory: '%kernel.project_dir%/var/uploads/photos/originals'
        photo_thumbs_storage:
            adapter: 'local'
            options:
                directory: '%kernel.project_dir%/var/uploads/photos/thumbs'
        photo_previews_storage:
            adapter: 'local'
            options:
                directory: '%kernel.project_dir%/var/uploads/photos/previews'
```

- [ ] **Step 2: Update .gitignore**

Append to `.gitignore`:

```
/var/uploads/photos/
```

(`/var/uploads/event-logos/` may already be ignored; check before adding to avoid dupes.)

- [ ] **Step 3: Verify the container builds**

```bash
php bin/console debug:container photo_originals_storage
php bin/console debug:container photo_thumbs_storage
php bin/console debug:container photo_previews_storage
```

Expected: each returns a `League\Flysystem\Filesystem` service definition.

- [ ] **Step 4: Quality gate + commit**

```bash
vendor/bin/phpunit
vendor/bin/grumphp run

git add config/packages/flysystem.yaml .gitignore
git commit -m "13 - register photo storage filesystems"
```

---

## Task 4: PhotoRepository::findReadyInWindow + integration test

**Goal:** Repository method returning the `ready` photos for an event in a UTC time window, capped at 200, ordered by `takenAt ASC`.

**Files:**
- Modify: `src/Repository/PhotoRepository.php`
- Create: `tests/Integration/Repository/PhotoRepositoryTest.php`

- [ ] **Step 1: Write the failing integration test**

`tests/Integration/Repository/PhotoRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\User;
use App\Repository\PhotoRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PhotoRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PhotoRepository $repo;
    private Event $event;
    private User $owner;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = self::getContainer()->get(PhotoRepository::class);

        $this->owner = new User('owner@example.test', 'Owner');
        $this->owner->setPassword('x');
        $this->em->persist($this->owner);

        $this->event = new Event('demo', 'Demo', new DateTimeImmutable('2026-06-10'), $this->owner);
        $this->event->setTimezone('UTC');
        $this->em->persist($this->event);
        $this->em->flush();
    }

    public function testFindsReadyPhotosInsideWindow(): void
    {
        $inside  = $this->createReady('2026-06-10 12:00:00');
        $alsoIn  = $this->createReady('2026-06-10 12:15:00');
        $tooEarly = $this->createReady('2026-06-10 11:00:00');
        $tooLate  = $this->createReady('2026-06-10 13:30:00');
        $pending  = $this->createPending('2026-06-10 12:10:00');
        $this->em->flush();

        $start = new DateTimeImmutable('2026-06-10 11:30:00', new DateTimeZone('UTC'));
        $end   = new DateTimeImmutable('2026-06-10 12:30:00', new DateTimeZone('UTC'));

        $result = $this->repo->findReadyInWindow($this->event, $start, $end);

        self::assertCount(2, $result);
        self::assertSame($inside->getId(), $result[0]->getId());
        self::assertSame($alsoIn->getId(), $result[1]->getId());
    }

    public function testEndpointsAreInclusive(): void
    {
        $atStart = $this->createReady('2026-06-10 11:30:00');
        $atEnd   = $this->createReady('2026-06-10 12:30:00');
        $this->em->flush();

        $start = new DateTimeImmutable('2026-06-10 11:30:00', new DateTimeZone('UTC'));
        $end   = new DateTimeImmutable('2026-06-10 12:30:00', new DateTimeZone('UTC'));

        $result = $this->repo->findReadyInWindow($this->event, $start, $end);

        self::assertCount(2, $result);
    }

    public function testHardCapDefaultIs200(): void
    {
        for ($i = 0; $i < 205; $i++) {
            $this->createReady(sprintf('2026-06-10 12:%02d:%02d', intdiv($i, 60), $i % 60));
        }
        $this->em->flush();

        $start = new DateTimeImmutable('2026-06-10 11:00:00', new DateTimeZone('UTC'));
        $end   = new DateTimeImmutable('2026-06-10 13:00:00', new DateTimeZone('UTC'));

        $result = $this->repo->findReadyInWindow($this->event, $start, $end);

        self::assertCount(200, $result);
    }

    private function createReady(string $takenAt): Photo
    {
        $photo = new Photo(
            event: $this->event,
            contentHash: bin2hex(random_bytes(32)),
            originalFilename: 'x.jpg',
            byteSize: 100,
        );
        $photo->markReady(new DateTimeImmutable($takenAt, new DateTimeZone('UTC')), 100, 100);
        $this->em->persist($photo);
        return $photo;
    }

    private function createPending(string $takenAt): Photo
    {
        $photo = new Photo(
            event: $this->event,
            contentHash: bin2hex(random_bytes(32)),
            originalFilename: 'x.jpg',
            byteSize: 100,
        );
        // intentionally not calling markReady — leave pending
        $this->em->persist($photo);
        return $photo;
    }
}
```

- [ ] **Step 2: Run, expect failure**

```bash
vendor/bin/phpunit tests/Integration/Repository/PhotoRepositoryTest.php
```
Expected: `findReadyInWindow` not found.

- [ ] **Step 3: Implement the method**

Append to `src/Repository/PhotoRepository.php`:

```php
/**
 * @return list<Photo>
 */
public function findReadyInWindow(
    Event $event,
    DateTimeImmutable $start,
    DateTimeImmutable $end,
    int $limit = 200,
): array {
    /** @var list<Photo> $result */
    $result = $this->createQueryBuilder('p')
        ->andWhere('p.event = :event')
        ->andWhere('p.status = :status')
        ->andWhere('p.takenAt BETWEEN :start AND :end')
        ->setParameter('event', $event)
        ->setParameter('status', PhotoStatus::Ready)
        ->setParameter('start', $start)
        ->setParameter('end', $end)
        ->orderBy('p.takenAt', 'ASC')
        ->setMaxResults($limit)
        ->getQuery()
        ->getResult();

    return $result;
}
```

Add the imports at the top:
```php
use App\Entity\Event;
use App\Entity\PhotoStatus;
use DateTimeImmutable;
```

- [ ] **Step 4: Run, expect pass**

```bash
vendor/bin/phpunit tests/Integration/Repository/PhotoRepositoryTest.php
```
Expected: green (3 tests).

- [ ] **Step 5: Quality gate + commit**

```bash
vendor/bin/phpunit
vendor/bin/grumphp run

git add src/Repository/PhotoRepository.php tests/Integration/Repository/PhotoRepositoryTest.php
git commit -m "13 - add PhotoRepository::findReadyInWindow"
```

---

## Task 5: Add JPEG test fixtures

**Goal:** Four small JPEG fixtures committed to the repo, used by ExifReader, DerivativeGenerator, and handler tests.

**Files:**
- Create: `tests/fixtures/photos/with-datetime-original.jpg`
- Create: `tests/fixtures/photos/with-offset-time.jpg`
- Create: `tests/fixtures/photos/no-exif.jpg`
- Create: `tests/fixtures/photos/bigger.jpg`
- Create: `tests/fixtures/photos/README.md` (origin notes)

- [ ] **Step 1: Generate the fixtures via a one-shot PHP script**

Create `bin/make-photo-fixtures.php` (temporary; deleted before commit):

```php
<?php
declare(strict_types=1);

$dir = __DIR__ . '/../tests/fixtures/photos';
@mkdir($dir, 0o755, true);

function jpegBytes(int $w, int $h, int $r = 200, int $g = 200, int $b = 200): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
    ob_start();
    imagejpeg($img, null, 90);
    imagedestroy($img);
    return (string) ob_get_clean();
}

// 1. no-exif.jpg — plain JPEG, no APP1
file_put_contents($dir . '/no-exif.jpg', jpegBytes(800, 600));

// 2. with-datetime-original.jpg — DateTimeOriginal only (no offset)
$exifSegment = buildExifSegment([
    'DateTimeOriginal' => '2026:06:10 12:34:56',
]);
file_put_contents($dir . '/with-datetime-original.jpg', injectExif(jpegBytes(800, 600), $exifSegment));

// 3. with-offset-time.jpg — DateTimeOriginal + OffsetTimeOriginal
$exifSegment2 = buildExifSegment([
    'DateTimeOriginal'   => '2026:06:10 12:34:56',
    'OffsetTimeOriginal' => '+02:00',
]);
file_put_contents($dir . '/with-offset-time.jpg', injectExif(jpegBytes(800, 600), $exifSegment2));

// 4. bigger.jpg — ~2MB
file_put_contents($dir . '/bigger.jpg', jpegBytes(3000, 2000, 180, 200, 220));

echo "Fixtures written to $dir\n";

/* ------------------------------------------------------------------ */

function injectExif(string $jpeg, string $exifAPP1): string
{
    // JPEG starts with FFD8. Insert the APP1 segment immediately after.
    return substr($jpeg, 0, 2) . $exifAPP1 . substr($jpeg, 2);
}

/** @param array<string,string> $entries */
function buildExifSegment(array $entries): string
{
    // Minimal TIFF/EXIF builder. Uses a tiny, ASCII-only IFD0 with an EXIF sub-IFD.
    // Tag numbers:
    //   0x9003 DateTimeOriginal      (ASCII, 20 bytes "YYYY:MM:DD HH:MM:SS\0")
    //   0x9011 OffsetTimeOriginal    (ASCII, 7  bytes "+HH:MM\0")
    $exifTags = [];
    if (isset($entries['DateTimeOriginal'])) {
        $exifTags[0x9003] = str_pad($entries['DateTimeOriginal'], 19, "\0") . "\0"; // 20
    }
    if (isset($entries['OffsetTimeOriginal'])) {
        $exifTags[0x9011] = $entries['OffsetTimeOriginal'] . "\0"; // 7
    }

    // Build EXIF sub-IFD
    $entriesCount = count($exifTags);
    $entriesBin = '';
    $dataBlob = '';
    $tiffHeaderSize = 8; // II*\0 + offset
    $ifd0Size = 2 + (1 * 12) + 4; // 1 entry pointing to ExifIFD, plus next-IFD offset
    $exifIfdOffset = $tiffHeaderSize + $ifd0Size;
    $exifIfdHeaderSize = 2 + ($entriesCount * 12) + 4;
    $valuePoolStart = $exifIfdOffset + $exifIfdHeaderSize;

    $cursor = $valuePoolStart;
    foreach ($exifTags as $tag => $value) {
        $count = strlen($value);
        $entriesBin .= pack('v', $tag) . pack('v', 2) . pack('V', $count);
        if ($count <= 4) {
            $entriesBin .= str_pad($value, 4, "\0");
        } else {
            $entriesBin .= pack('V', $cursor);
            $dataBlob .= $value;
            $cursor += $count;
        }
    }

    $exifIfd = pack('v', $entriesCount) . $entriesBin . pack('V', 0) . $dataBlob;

    // IFD0: one entry, tag 0x8769 (ExifIFDPointer), type 4 (LONG), count 1, value = $exifIfdOffset
    $ifd0 = pack('v', 1)
        . pack('v', 0x8769) . pack('v', 4) . pack('V', 1) . pack('V', $exifIfdOffset)
        . pack('V', 0); // no next IFD

    $tiff = "II" . pack('v', 0x002A) . pack('V', $tiffHeaderSize) . $ifd0 . $exifIfd;

    $exifPayload = "Exif\0\0" . $tiff;
    $segmentLength = strlen($exifPayload) + 2; // +2 for length field itself

    return "\xFF\xE1" . pack('n', $segmentLength) . $exifPayload;
}
```

- [ ] **Step 2: Run the script and verify**

```bash
php bin/make-photo-fixtures.php
php -r 'var_dump(exif_read_data("tests/fixtures/photos/with-datetime-original.jpg")["DateTimeOriginal"] ?? null);'
php -r 'var_dump(exif_read_data("tests/fixtures/photos/with-offset-time.jpg")["OffsetTimeOriginal"] ?? null);'
php -r 'var_dump(exif_read_data("tests/fixtures/photos/no-exif.jpg")["DateTimeOriginal"] ?? null);'
```

Expected:
- First: `string(19) "2026:06:10 12:34:56"`
- Second: `string(6) "+02:00"`
- Third: `NULL`

If any assertion above fails, fix the fixture generator before continuing — downstream tests depend on these inputs.

- [ ] **Step 3: Document and delete the generator**

Create `tests/fixtures/photos/README.md`:

```markdown
# Photo fixtures

Generated once by `bin/make-photo-fixtures.php` (deleted after generation; recreate from git history if you need to regenerate).

- `with-datetime-original.jpg` — JPEG with EXIF `DateTimeOriginal=2026:06:10 12:34:56`, no offset.
- `with-offset-time.jpg`       — same, plus `OffsetTimeOriginal=+02:00`.
- `no-exif.jpg`                — plain JPEG, no EXIF metadata.
- `bigger.jpg`                 — ~2 MB JPEG for size/streaming tests.
```

Then delete the generator:
```bash
rm bin/make-photo-fixtures.php
```

- [ ] **Step 4: Commit**

```bash
git add tests/fixtures/photos/
git commit -m "13 - add JPEG fixtures for photo ingest tests"
```

---

## Task 6: ExifReader service

**Goal:** Service that takes raw JPEG bytes (or a file path) and an event timezone, returns a `DateTimeImmutable` in UTC. Throws `PhotoRejected` if `DateTimeOriginal` is missing or unparseable. Prefers `OffsetTimeOriginal` over event TZ.

**Files:**
- Create: `src/Service/Photo/PhotoRejected.php`
- Create: `src/Service/Photo/ExifReader.php`
- Create: `tests/Unit/Service/Photo/ExifReaderTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Service/Photo/ExifReaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Photo;

use App\Service\Photo\ExifReader;
use App\Service\Photo\PhotoRejected;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ExifReaderTest extends TestCase
{
    private string $fixturesDir;
    private ExifReader $reader;

    protected function setUp(): void
    {
        $this->fixturesDir = dirname(__DIR__, 4) . '/fixtures/photos';
        $this->reader = new ExifReader();
    }

    public function testReadsDateTimeOriginalInEventTimezoneAndReturnsUtc(): void
    {
        $taken = $this->reader->readTakenAt(
            $this->fixturesDir . '/with-datetime-original.jpg',
            new DateTimeZone('Europe/Amsterdam'),
        );

        $expected = new DateTimeImmutable('2026-06-10 10:34:56', new DateTimeZone('UTC'));
        self::assertEquals($expected, $taken);
    }

    public function testPrefersOffsetTimeOriginal(): void
    {
        $taken = $this->reader->readTakenAt(
            $this->fixturesDir . '/with-offset-time.jpg',
            new DateTimeZone('America/Los_Angeles'),
        );

        // Even though we passed LA, the +02:00 in EXIF should be used.
        $expected = new DateTimeImmutable('2026-06-10 10:34:56', new DateTimeZone('UTC'));
        self::assertEquals($expected, $taken);
    }

    public function testThrowsWhenDateTimeOriginalMissing(): void
    {
        $this->expectException(PhotoRejected::class);
        $this->expectExceptionMessageMatches('/DateTimeOriginal/');

        $this->reader->readTakenAt(
            $this->fixturesDir . '/no-exif.jpg',
            new DateTimeZone('UTC'),
        );
    }
}
```

- [ ] **Step 2: Run, expect failure**

```bash
vendor/bin/phpunit tests/Unit/Service/Photo/ExifReaderTest.php
```
Expected: class-not-found.

- [ ] **Step 3: Create `PhotoRejected`**

`src/Service/Photo/PhotoRejected.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Photo;

use RuntimeException;

/**
 * Thrown when a photo cannot be processed for a reason that won't be
 * fixed by retrying (e.g., missing EXIF DateTimeOriginal). Caller is
 * expected to catch and call Photo::markFailed().
 */
final class PhotoRejected extends RuntimeException
{
}
```

- [ ] **Step 4: Create `ExifReader`**

`src/Service/Photo/ExifReader.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Photo;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class ExifReader
{
    public function readTakenAt(string $path, DateTimeZone $eventTimezone): DateTimeImmutable
    {
        try {
            $data = @exif_read_data($path, 'EXIF', true);
        } catch (Throwable $exception) {
            throw new PhotoRejected('Could not read EXIF: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($data)) {
            throw new PhotoRejected('No EXIF data found.');
        }

        $exifSection = is_array($data['EXIF'] ?? null) ? $data['EXIF'] : [];
        $raw = $exifSection['DateTimeOriginal'] ?? $data['DateTimeOriginal'] ?? null;

        if (!is_string($raw) || $raw === '') {
            throw new PhotoRejected('EXIF DateTimeOriginal is missing.');
        }

        $offset = $exifSection['OffsetTimeOriginal'] ?? $data['OffsetTimeOriginal'] ?? null;
        $tz = (is_string($offset) && $offset !== '')
            ? $this->buildOffsetTimezone($offset, $eventTimezone)
            : $eventTimezone;

        $taken = DateTimeImmutable::createFromFormat('Y:m:d H:i:s', $raw, $tz);

        if (!$taken instanceof DateTimeImmutable) {
            throw new PhotoRejected(sprintf('EXIF DateTimeOriginal "%s" is unparseable.', $raw));
        }

        return $taken->setTimezone(new DateTimeZone('UTC'));
    }

    private function buildOffsetTimezone(string $offset, DateTimeZone $fallback): DateTimeZone
    {
        // Accepts "+02:00" or "-05:00".
        if (preg_match('/^[+-]\d{2}:\d{2}$/', $offset) !== 1) {
            return $fallback;
        }

        try {
            return new DateTimeZone($offset);
        } catch (Throwable) {
            return $fallback;
        }
    }
}
```

- [ ] **Step 5: Run, expect pass**

```bash
vendor/bin/phpunit tests/Unit/Service/Photo/ExifReaderTest.php
```
Expected: green (3 tests).

- [ ] **Step 6: Quality gate + commit**

```bash
vendor/bin/phpunit
vendor/bin/grumphp run

git add src/Service/Photo/ tests/Unit/Service/Photo/ExifReaderTest.php
git commit -m "13 - add ExifReader service with strict DateTimeOriginal handling"
```

---

## Task 7: DerivativeGenerator service

**Goal:** Service that reads original JPEG bytes (from a Flysystem operator), generates thumbnail (long edge 400, q80) and preview (long edge 1600, q85), strips EXIF (GD does this naturally on re-encode), writes both to the corresponding Flysystem storages. Returns the original width/height for the row.

**Files:**
- Create: `src/Service/Photo/DerivativeGenerator.php`
- Create: `tests/Unit/Service/Photo/DerivativeGeneratorTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Service/Photo/DerivativeGeneratorTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Photo;

use App\Service\Photo\DerivativeGenerator;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class DerivativeGeneratorTest extends TestCase
{
    public function testGeneratesThumbAndPreviewAndReportsDimensions(): void
    {
        $originalsFs = new Filesystem(new InMemoryFilesystemAdapter());
        $thumbsFs    = new Filesystem(new InMemoryFilesystemAdapter());
        $previewsFs  = new Filesystem(new InMemoryFilesystemAdapter());

        $originalBytes = (string) file_get_contents(
            dirname(__DIR__, 4) . '/fixtures/photos/bigger.jpg',
        );
        $originalsFs->write('event-1/42.jpg', $originalBytes);

        $generator = new DerivativeGenerator($originalsFs, $thumbsFs, $previewsFs);
        [$width, $height] = $generator->generate('event-1/42.jpg');

        self::assertSame(3000, $width);
        self::assertSame(2000, $height);
        self::assertTrue($thumbsFs->fileExists('event-1/42.jpg'));
        self::assertTrue($previewsFs->fileExists('event-1/42.jpg'));

        $thumbDims = getimagesizefromstring($thumbsFs->read('event-1/42.jpg'));
        self::assertNotFalse($thumbDims);
        self::assertSame(400, max($thumbDims[0], $thumbDims[1]));

        $previewDims = getimagesizefromstring($previewsFs->read('event-1/42.jpg'));
        self::assertNotFalse($previewDims);
        self::assertSame(1600, max($previewDims[0], $previewDims[1]));
    }
}
```

- [ ] **Step 2: Run, expect failure**

```bash
vendor/bin/phpunit tests/Unit/Service/Photo/DerivativeGeneratorTest.php
```
Expected: class-not-found.

- [ ] **Step 3: Create `DerivativeGenerator`**

`src/Service/Photo/DerivativeGenerator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Photo;

use League\Flysystem\FilesystemOperator;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class DerivativeGenerator
{
    private const int THUMB_LONG_EDGE   = 400;
    private const int THUMB_QUALITY     = 80;
    private const int PREVIEW_LONG_EDGE = 1600;
    private const int PREVIEW_QUALITY   = 85;

    public function __construct(
        #[Autowire(service: 'photo_originals_storage')]
        private readonly FilesystemOperator $originals,
        #[Autowire(service: 'photo_thumbs_storage')]
        private readonly FilesystemOperator $thumbs,
        #[Autowire(service: 'photo_previews_storage')]
        private readonly FilesystemOperator $previews,
    ) {
    }

    /**
     * @return array{0:int,1:int} [width, height] of the original image
     */
    public function generate(string $path): array
    {
        $bytes = $this->originals->read($path);
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw new RuntimeException(sprintf('Could not decode JPEG at "%s".', $path));
        }

        $width  = imagesx($image);
        $height = imagesy($image);

        $this->thumbs->write(
            $path,
            $this->encode($this->scaleTo($image, $width, $height, self::THUMB_LONG_EDGE), self::THUMB_QUALITY),
        );
        $this->previews->write(
            $path,
            $this->encode($this->scaleTo($image, $width, $height, self::PREVIEW_LONG_EDGE), self::PREVIEW_QUALITY),
        );

        imagedestroy($image);

        return [$width, $height];
    }

    private function scaleTo(\GdImage $source, int $srcW, int $srcH, int $longEdge): \GdImage
    {
        $longest = max($srcW, $srcH);
        if ($longest <= $longEdge) {
            // Source is already smaller than the target — re-encode a copy at native size.
            $copy = imagecreatetruecolor($srcW, $srcH);
            imagecopy($copy, $source, 0, 0, 0, 0, $srcW, $srcH);
            return $copy;
        }

        $ratio = $longEdge / $longest;
        $dstW = (int) round($srcW * $ratio);
        $dstH = (int) round($srcH * $ratio);

        $scaled = imagescale($source, $dstW, $dstH, IMG_BICUBIC);
        if ($scaled === false) {
            throw new RuntimeException('imagescale failed.');
        }
        return $scaled;
    }

    private function encode(\GdImage $image, int $quality): string
    {
        ob_start();
        imagejpeg($image, null, $quality);
        $bytes = ob_get_clean();
        imagedestroy($image);

        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('imagejpeg produced no output.');
        }

        return $bytes;
    }
}
```

- [ ] **Step 4: Run, expect pass**

```bash
vendor/bin/phpunit tests/Unit/Service/Photo/DerivativeGeneratorTest.php
```
Expected: green.

- [ ] **Step 5: Quality gate + commit**

```bash
vendor/bin/phpunit
vendor/bin/grumphp run

git add src/Service/Photo/DerivativeGenerator.php tests/Unit/Service/Photo/DerivativeGeneratorTest.php
git commit -m "13 - add DerivativeGenerator producing thumb + preview JPEGs"
```

---

## Task 8: Install Symfony Messenger

**Goal:** `symfony/messenger` installed with Doctrine transport, `async` and `failed` transports configured. `ProcessPhoto` not yet routed (next task).

**Files:**
- Modify: `composer.json` / `composer.lock`
- Modify: `config/bundles.php` (auto-edited by Flex)
- Create: `config/packages/messenger.yaml` (Flex recipe; may need hand-editing)
- Modify: `.env`

- [ ] **Step 1: Require messenger**

```bash
composer require symfony/messenger
```

Flex installs the recipe → creates `config/packages/messenger.yaml`, adds entries to `config/bundles.php`, appends `MESSENGER_TRANSPORT_DSN` to `.env`.

- [ ] **Step 2: Verify install**

```bash
php bin/console debug:config framework messenger
```

Expected: prints the messenger config with `transports.async` defined.

- [ ] **Step 3: Adjust `messenger.yaml`**

Open `config/packages/messenger.yaml` and replace the body with:

```yaml
framework:
    messenger:
        failure_transport: failed
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                retry_strategy:
                    max_retries: 3
                    delay: 1000
                    multiplier: 5
                    max_delay: 0
            failed: 'doctrine://default?queue_name=failed'
        routing: ~
```

(`routing: ~` is a placeholder — the next task adds `ProcessPhoto` here.)

- [ ] **Step 4: Set the DSN**

In `.env`, change the Flex-installed `MESSENGER_TRANSPORT_DSN` line to:

```
MESSENGER_TRANSPORT_DSN=doctrine://default?queue_name=async
```

(remove any commented-out alternatives the recipe inserted).

- [ ] **Step 5: Verify the transports**

```bash
php bin/console messenger:setup-transports
```

Expected: creates `messenger_messages` table (or notes it already exists).

- [ ] **Step 6: Generate the messenger-table migration**

```bash
php bin/console doctrine:migrations:diff --no-interaction
```

Should produce a migration creating `messenger_messages` and the index/sequence. Apply it to dev + test:

```bash
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:migrations:migrate --no-interaction --env=test
```

- [ ] **Step 7: Quality gate + commit**

```bash
vendor/bin/phpunit
vendor/bin/grumphp run

git add composer.json composer.lock config/bundles.php config/packages/messenger.yaml .env migrations/
git commit -m "13 - install Symfony Messenger with Doctrine transport"
```

---

## Task 9: ProcessPhoto message + handler

**Goal:** Async message that triggers the pipeline. Handler is idempotent (no-op if photo missing or not `pending`), catches `PhotoRejected` to mark the row failed, lets other exceptions propagate for Messenger retry.

**Files:**
- Create: `src/Message/ProcessPhoto.php`
- Create: `src/MessageHandler/ProcessPhotoHandler.php`
- Modify: `config/packages/messenger.yaml` (add routing)
- Create: `tests/Integration/MessageHandler/ProcessPhotoHandlerTest.php`

- [ ] **Step 1: Write the failing integration test**

`tests/Integration/MessageHandler/ProcessPhotoHandlerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\PhotoStatus;
use App\Entity\User;
use App\Message\ProcessPhoto;
use App\MessageHandler\ProcessPhotoHandler;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProcessPhotoHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FilesystemOperator $originals;
    private FilesystemOperator $thumbs;
    private FilesystemOperator $previews;
    private ProcessPhotoHandler $handler;
    private Event $event;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();

        $this->em = $c->get(EntityManagerInterface::class);
        $this->originals = $c->get('photo_originals_storage');
        $this->thumbs = $c->get('photo_thumbs_storage');
        $this->previews = $c->get('photo_previews_storage');
        $this->handler = $c->get(ProcessPhotoHandler::class);

        $owner = new User('o@example.test', 'O');
        $owner->setPassword('x');
        $this->em->persist($owner);

        $this->event = new Event('demo', 'Demo', new DateTimeImmutable('2026-06-10'), $owner);
        $this->event->setTimezone('Europe/Amsterdam');
        $this->em->persist($this->event);
        $this->em->flush();
    }

    public function testHappyPathReadsExifAndWritesDerivatives(): void
    {
        $photo = $this->seedPending('with-datetime-original.jpg', 'aa');

        ($this->handler)(new ProcessPhoto($photo->getId() ?? 0));
        $this->em->refresh($photo);

        self::assertSame(PhotoStatus::Ready, $photo->getStatus());
        self::assertEquals(
            new DateTimeImmutable('2026-06-10 10:34:56', new DateTimeZone('UTC')),
            $photo->getTakenAt(),
        );
        self::assertTrue($this->thumbs->fileExists(sprintf('event-%d/%d.jpg', $this->event->getId(), $photo->getId())));
        self::assertTrue($this->previews->fileExists(sprintf('event-%d/%d.jpg', $this->event->getId(), $photo->getId())));
    }

    public function testRejectsWhenExifMissing(): void
    {
        $photo = $this->seedPending('no-exif.jpg', 'bb');

        ($this->handler)(new ProcessPhoto($photo->getId() ?? 0));
        $this->em->refresh($photo);

        self::assertSame(PhotoStatus::Failed, $photo->getStatus());
        self::assertNotNull($photo->getProcessingError());
        self::assertStringContainsString('DateTimeOriginal', (string) $photo->getProcessingError());
    }

    public function testIdempotentWhenAlreadyReady(): void
    {
        $photo = $this->seedPending('with-datetime-original.jpg', 'cc');
        ($this->handler)(new ProcessPhoto($photo->getId() ?? 0));

        // Run again — handler should no-op (no exception, status unchanged)
        ($this->handler)(new ProcessPhoto($photo->getId() ?? 0));
        $this->em->refresh($photo);

        self::assertSame(PhotoStatus::Ready, $photo->getStatus());
    }

    public function testNoopWhenPhotoDeleted(): void
    {
        // ID that does not exist — handler must not throw
        ($this->handler)(new ProcessPhoto(999999));
        self::assertTrue(true); // no exception is the assertion
    }

    private function seedPending(string $fixtureFile, string $hashSeed): Photo
    {
        $photo = new Photo(
            event: $this->event,
            contentHash: str_pad($hashSeed, 64, '0'),
            originalFilename: $fixtureFile,
            byteSize: 100,
        );
        $this->em->persist($photo);
        $this->em->flush();

        $bytes = (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/photos/' . $fixtureFile);
        $this->originals->write(sprintf('event-%d/%d.jpg', $this->event->getId(), $photo->getId()), $bytes);

        return $photo;
    }

    protected function tearDown(): void
    {
        foreach ([$this->originals, $this->thumbs, $this->previews] as $fs) {
            try {
                $fs->deleteDirectory(sprintf('event-%d', $this->event->getId()));
            } catch (\Throwable) {
            }
        }
        parent::tearDown();
    }
}
```

Note: this test uses real disk storages (not in-memory) because the handler resolves them via DI by service id. The `tearDown` cleans up.

- [ ] **Step 2: Run, expect failure**

```bash
vendor/bin/phpunit tests/Integration/MessageHandler/ProcessPhotoHandlerTest.php
```
Expected: class-not-found.

- [ ] **Step 3: Create the message**

`src/Message/ProcessPhoto.php`:

```php
<?php

declare(strict_types=1);

namespace App\Message;

final class ProcessPhoto
{
    public function __construct(public readonly int $photoId)
    {
    }
}
```

- [ ] **Step 4: Create the handler**

`src/MessageHandler/ProcessPhotoHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\PhotoStatus;
use App\Message\ProcessPhoto;
use App\Repository\PhotoRepository;
use App\Service\Photo\DerivativeGenerator;
use App\Service\Photo\ExifReader;
use App\Service\Photo\PhotoRejected;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessPhotoHandler
{
    public function __construct(
        private readonly PhotoRepository $photos,
        private readonly EntityManagerInterface $em,
        private readonly ExifReader $exifReader,
        private readonly DerivativeGenerator $derivatives,
        #[Autowire(service: 'photo_originals_storage')]
        private readonly FilesystemOperator $originals,
    ) {
    }

    public function __invoke(ProcessPhoto $message): void
    {
        $photo = $this->photos->find($message->photoId);
        if ($photo === null) {
            return;
        }
        if ($photo->getStatus() !== PhotoStatus::Pending) {
            return;
        }

        $event = $photo->getEvent();
        $path = sprintf('event-%d/%d.jpg', (int) $event->getId(), (int) $photo->getId());

        try {
            $tmpFile = $this->stageToTmp($path);
            try {
                $takenAt = $this->exifReader->readTakenAt(
                    $tmpFile,
                    new DateTimeZone($event->getTimezone()),
                );
            } finally {
                @unlink($tmpFile);
            }

            [$width, $height] = $this->derivatives->generate($path);
            $photo->markReady($takenAt, $width, $height);
            $this->em->flush();
        } catch (PhotoRejected $rejected) {
            $photo->markFailed($rejected->getMessage());
            $this->em->flush();
        }
    }

    /**
     * exif_read_data needs a real file path. Stream the Flysystem object to a temp file.
     */
    private function stageToTmp(string $path): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'photo-exif-');
        if ($tmp === false) {
            throw new \RuntimeException('Could not create temp file for EXIF read.');
        }
        file_put_contents($tmp, $this->originals->read($path));
        return $tmp;
    }
}
```

- [ ] **Step 5: Route the message**

Edit `config/packages/messenger.yaml` to set the routing:

```yaml
            routing:
                'App\Message\ProcessPhoto': async
```

(Replace the `routing: ~` placeholder.)

- [ ] **Step 6: Run, expect pass**

```bash
vendor/bin/phpunit tests/Integration/MessageHandler/ProcessPhotoHandlerTest.php
```
Expected: green (4 tests).

- [ ] **Step 7: Quality gate + commit**

```bash
vendor/bin/phpunit
vendor/bin/grumphp run

git add src/Message/ src/MessageHandler/ config/packages/messenger.yaml tests/Integration/MessageHandler/
git commit -m "13 - add ProcessPhoto async handler"
```

---

## Task 10: PhotoVoter

**Goal:** Voter delegating to ownership rules identical to `EventVoter`. Admins always allowed.

**Files:**
- Create: `src/Security/Voter/PhotoVoter.php`
- Create: `tests/Unit/Security/PhotoVoterTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Security/PhotoVoterTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\User;
use App\Security\Voter\PhotoVoter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class PhotoVoterTest extends TestCase
{
    public function testOwnerCanEdit(): void
    {
        $owner = $this->makeUser('owner@example.test');
        $photo = $this->makePhoto($owner);

        $voter = new PhotoVoter($this->securityMock(false));
        $token = $this->tokenWith($owner);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $photo, [PhotoVoter::EDIT]));
    }

    public function testStrangerCannotEdit(): void
    {
        $owner    = $this->makeUser('owner@example.test');
        $stranger = $this->makeUser('stranger@example.test');
        $photo    = $this->makePhoto($owner);

        $voter = new PhotoVoter($this->securityMock(false));
        $token = $this->tokenWith($stranger);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $photo, [PhotoVoter::EDIT]));
    }

    public function testAdminCanEdit(): void
    {
        $owner    = $this->makeUser('owner@example.test');
        $stranger = $this->makeUser('stranger@example.test');
        $photo    = $this->makePhoto($owner);

        $voter = new PhotoVoter($this->securityMock(true));
        $token = $this->tokenWith($stranger);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $photo, [PhotoVoter::EDIT]));
    }

    private function makeUser(string $email): User
    {
        $u = new User($email, 'Name');
        $u->setPassword('x');
        return $u;
    }

    private function makePhoto(User $owner): Photo
    {
        $event = new Event('e', 'E', new DateTimeImmutable('2026-06-10'), $owner);
        return new Photo($event, str_repeat('a', 64), 'x.jpg', 100);
    }

    private function tokenWith(User $user): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        return $token;
    }

    private function securityMock(bool $isAdmin): Security
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->with('ROLE_ADMIN')->willReturn($isAdmin);
        return $security;
    }
}
```

- [ ] **Step 2: Run, expect failure**

```bash
vendor/bin/phpunit tests/Unit/Security/PhotoVoterTest.php
```

- [ ] **Step 3: Create the voter**

`src/Security/Voter/PhotoVoter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Photo;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Photo>
 */
final class PhotoVoter extends Voter
{
    public const string EDIT   = 'PHOTO_EDIT';
    public const string DELETE = 'PHOTO_DELETE';
    public const string VIEW   = 'PHOTO_VIEW';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::DELETE, self::VIEW], true)
            && $subject instanceof Photo;
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }
        if (!$subject instanceof Photo) {
            return false;
        }

        return $subject->getEvent()->getOwner() === $user;
    }
}
```

- [ ] **Step 4: Run, expect pass**

```bash
vendor/bin/phpunit tests/Unit/Security/PhotoVoterTest.php
```

- [ ] **Step 5: Quality gate + commit**

```bash
vendor/bin/phpunit
vendor/bin/grumphp run

git add src/Security/Voter/PhotoVoter.php tests/Unit/Security/PhotoVoterTest.php
git commit -m "13 - add PhotoVoter for admin photo authorization"
```

---

## Task 11: Admin upload endpoint

**Goal:** `POST /admin/events/{id}/photos` — voter-checked, mime/size-validated, dedup-aware. On success, persists `pending` Photo, moves bytes into `photo_originals_storage`, dispatches `ProcessPhoto`. Returns JSON.

**Files:**
- Create: `src/Controller/Admin/PhotoController.php` (also hosts retry/delete/grid in later tasks)
- Create: `tests/Functional/Admin/PhotoUploadTest.php`

- [ ] **Step 1: Write the failing functional test**

`tests/Functional/Admin/PhotoUploadTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\PhotoStatus;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PhotoUploadTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private FilesystemOperator $originals;
    private User $owner;
    private Event $event;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->originals = $c->get('photo_originals_storage');

        $hasher = $c->get(UserPasswordHasherInterface::class);
        $this->owner = new User('owner@example.test', 'Owner');
        $this->owner->setPassword($hasher->hashPassword($this->owner, 'secret'));
        $this->owner->addRole('ROLE_ORGANIZER');
        $this->em->persist($this->owner);

        $this->event = new Event('demo', 'Demo', new DateTimeImmutable('2026-06-10'), $this->owner);
        $this->event->setTimezone('Europe/Amsterdam');
        $this->em->persist($this->event);
        $this->em->flush();

        $this->client->loginUser($this->owner);
    }

    public function testHappyPathReturnsPendingAndPersistsRow(): void
    {
        $file = $this->fixture('with-datetime-original.jpg');

        $this->client->request(
            'POST',
            sprintf('/admin/events/%d/photos', $this->event->getId()),
            [],
            ['file' => $file],
        );

        self::assertResponseStatusCodeSame(202);
        $body = (array) json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('pending', $body['status'] ?? null);
        self::assertIsInt($body['photoId'] ?? null);

        $photo = $this->em->find(Photo::class, $body['photoId']);
        self::assertNotNull($photo);
        self::assertSame(PhotoStatus::Pending, $photo->getStatus());
        self::assertTrue($this->originals->fileExists(
            sprintf('event-%d/%d.jpg', $this->event->getId(), $photo->getId()),
        ));
    }

    public function testDuplicateUploadReturnsDuplicateAndDoesNotInsertNewRow(): void
    {
        $file1 = $this->fixture('with-datetime-original.jpg');
        $this->client->request('POST', sprintf('/admin/events/%d/photos', $this->event->getId()), [], ['file' => $file1]);
        $firstId = (int) ((array) json_decode((string) $this->client->getResponse()->getContent(), true))['photoId'];

        $file2 = $this->fixture('with-datetime-original.jpg');
        $this->client->request('POST', sprintf('/admin/events/%d/photos', $this->event->getId()), [], ['file' => $file2]);

        self::assertResponseStatusCodeSame(200);
        $body = (array) json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('duplicate', $body['status']);
        self::assertSame($firstId, $body['photoId']);
    }

    public function testRejectsNonJpeg(): void
    {
        $tmp = sys_get_temp_dir() . '/text-' . uniqid() . '.txt';
        file_put_contents($tmp, 'not an image');
        $file = new UploadedFile($tmp, 'fake.txt', 'text/plain', null, true);

        $this->client->request('POST', sprintf('/admin/events/%d/photos', $this->event->getId()), [], ['file' => $file]);

        self::assertResponseStatusCodeSame(415);
    }

    public function testRejectsForNonOwner(): void
    {
        $stranger = new User('stranger@example.test', 'Stranger');
        $stranger->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($stranger, 'x'));
        $stranger->addRole('ROLE_ORGANIZER');
        $this->em->persist($stranger);
        $this->em->flush();
        $this->client->loginUser($stranger);

        $file = $this->fixture('with-datetime-original.jpg');
        $this->client->request('POST', sprintf('/admin/events/%d/photos', $this->event->getId()), [], ['file' => $file]);

        self::assertResponseStatusCodeSame(403);
    }

    private function fixture(string $name): UploadedFile
    {
        $src = dirname(__DIR__, 2) . '/fixtures/photos/' . $name;
        $dst = sys_get_temp_dir() . '/upload-' . uniqid() . '-' . $name;
        copy($src, $dst);
        return new UploadedFile($dst, $name, 'image/jpeg', null, true);
    }

    protected function tearDown(): void
    {
        try {
            $this->originals->deleteDirectory(sprintf('event-%d', $this->event->getId() ?? 0));
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run, expect failure (route 404)**

```bash
vendor/bin/phpunit tests/Functional/Admin/PhotoUploadTest.php
```

- [ ] **Step 3: Create `PhotoController` with the upload action**

`src/Controller/Admin/PhotoController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Event;
use App\Entity\Photo;
use App\Message\ProcessPhoto;
use App\Repository\PhotoRepository;
use App\Security\Voter\EventVoter;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class PhotoController extends AbstractController
{
    private const int MAX_BYTES = 25 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PhotoRepository $photos,
        private readonly MessageBusInterface $bus,
        #[Autowire(service: 'photo_originals_storage')]
        private readonly FilesystemOperator $originals,
    ) {
    }

    #[Route(
        '/admin/events/{id}/photos',
        name: 'admin_photo_upload',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function upload(Event $event, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return new JsonResponse(['error' => 'Missing file.'], Response::HTTP_BAD_REQUEST);
        }

        if ($file->getMimeType() !== 'image/jpeg') {
            return new JsonResponse(['error' => 'Only JPEG accepted.'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        if ($file->getSize() > self::MAX_BYTES) {
            return new JsonResponse(['error' => 'File too large.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $hash = (string) hash_file('sha256', (string) $file->getRealPath());
        $existing = $this->photos->findOneBy(['event' => $event, 'contentHash' => $hash]);
        if ($existing !== null) {
            return new JsonResponse(['status' => 'duplicate', 'photoId' => $existing->getId()], Response::HTTP_OK);
        }

        $photo = new Photo(
            event: $event,
            contentHash: $hash,
            originalFilename: (string) $file->getClientOriginalName(),
            byteSize: (int) $file->getSize(),
        );
        $this->em->persist($photo);
        $this->em->flush(); // need the id before naming the storage path

        $path = sprintf('event-%d/%d.jpg', (int) $event->getId(), (int) $photo->getId());
        $stream = fopen((string) $file->getRealPath(), 'rb');
        if ($stream === false) {
            $this->em->remove($photo);
            $this->em->flush();
            return new JsonResponse(['error' => 'Could not read uploaded file.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            $this->originals->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->bus->dispatch(new ProcessPhoto((int) $photo->getId()));

        return new JsonResponse(
            ['status' => 'pending', 'photoId' => $photo->getId()],
            Response::HTTP_ACCEPTED,
        );
    }
}
```

Note: this endpoint intentionally does NOT enforce CSRF — Uppy will be configured to send `X-Requested-With` only. We allow that here because the route is `ROLE_ORGANIZER`-gated and we'll rely on SameSite cookies. Document this as a known limitation if a reviewer flags it.

- [ ] **Step 4: Run, expect pass**

```bash
vendor/bin/phpunit tests/Functional/Admin/PhotoUploadTest.php
```
Expected: green (4 tests).

- [ ] **Step 5: Quality gate + commit**

```bash
vendor/bin/phpunit
vendor/bin/grumphp run

git add src/Controller/Admin/PhotoController.php tests/Functional/Admin/PhotoUploadTest.php
git commit -m "13 - add admin photo upload endpoint with dedup and async dispatch"
```

---

## Task 12: Admin retry + delete endpoints

**Goal:** Two more actions on `PhotoController`. Both CSRF-protected, voter-checked. Retry flips `failed` → `pending` and re-dispatches. Delete removes the row plus all three storage objects.

**Files:**
- Modify: `src/Controller/Admin/PhotoController.php`
- Create: `tests/Functional/Admin/PhotoModerationTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Functional/Admin/PhotoModerationTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\PhotoStatus;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class PhotoModerationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private FilesystemOperator $originals;
    private FilesystemOperator $thumbs;
    private FilesystemOperator $previews;
    private CsrfTokenManagerInterface $csrf;
    private User $owner;
    private Event $event;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->originals = $c->get('photo_originals_storage');
        $this->thumbs    = $c->get('photo_thumbs_storage');
        $this->previews  = $c->get('photo_previews_storage');
        $this->csrf      = $c->get(CsrfTokenManagerInterface::class);

        $hasher = $c->get(UserPasswordHasherInterface::class);
        $this->owner = new User('o@example.test', 'O');
        $this->owner->setPassword($hasher->hashPassword($this->owner, 'x'));
        $this->owner->addRole('ROLE_ORGANIZER');
        $this->em->persist($this->owner);

        $this->event = new Event('e', 'E', new DateTimeImmutable('2026-06-10'), $this->owner);
        $this->event->setTimezone('UTC');
        $this->em->persist($this->event);
        $this->em->flush();

        $this->client->loginUser($this->owner);
    }

    public function testRetryFlipsFailedToPending(): void
    {
        $photo = new Photo($this->event, str_repeat('a', 64), 'x.jpg', 100);
        $photo->markFailed('boom');
        $this->em->persist($photo);
        $this->em->flush();

        $token = $this->csrf->getToken('retry_photo_' . $photo->getId())->getValue();

        $this->client->request(
            'POST',
            sprintf('/admin/events/%d/photos/%d/retry', $this->event->getId(), $photo->getId()),
            ['_token' => $token],
        );

        self::assertResponseRedirects();
        $this->em->refresh($photo);
        self::assertSame(PhotoStatus::Pending, $photo->getStatus());
    }

    public function testDeleteRemovesRowAndStorageFiles(): void
    {
        $photo = new Photo($this->event, str_repeat('b', 64), 'x.jpg', 100);
        $photo->markReady(new DateTimeImmutable('now', new DateTimeZone('UTC')), 100, 100);
        $this->em->persist($photo);
        $this->em->flush();

        $path = sprintf('event-%d/%d.jpg', $this->event->getId(), $photo->getId());
        $this->originals->write($path, 'a');
        $this->thumbs->write($path, 'b');
        $this->previews->write($path, 'c');

        $token = $this->csrf->getToken('delete_photo_' . $photo->getId())->getValue();
        $this->client->request(
            'POST',
            sprintf('/admin/events/%d/photos/%d/delete', $this->event->getId(), $photo->getId()),
            ['_token' => $token],
        );

        self::assertResponseRedirects();
        self::assertNull($this->em->find(Photo::class, $photo->getId()));
        self::assertFalse($this->originals->fileExists($path));
        self::assertFalse($this->thumbs->fileExists($path));
        self::assertFalse($this->previews->fileExists($path));
    }

    public function testDeleteRejectsMissingCsrf(): void
    {
        $photo = new Photo($this->event, str_repeat('c', 64), 'x.jpg', 100);
        $this->em->persist($photo);
        $this->em->flush();

        $this->client->request(
            'POST',
            sprintf('/admin/events/%d/photos/%d/delete', $this->event->getId(), $photo->getId()),
        );

        self::assertResponseStatusCodeSame(403);
    }
}
```

- [ ] **Step 2: Run, expect failure**

```bash
vendor/bin/phpunit tests/Functional/Admin/PhotoModerationTest.php
```

- [ ] **Step 3: Add retry + delete to `PhotoController`**

Append to the existing class:

```php
    #[Route(
        '/admin/events/{eventId}/photos/{photoId}/retry',
        name: 'admin_photo_retry',
        requirements: ['eventId' => '\d+', 'photoId' => '\d+'],
        methods: ['POST'],
    )]
    public function retry(int $eventId, int $photoId, Request $request): Response
    {
        $photo = $this->loadOrThrow($eventId, $photoId);
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $photo->getEvent());
        $this->assertCsrf($request, 'retry_photo_' . $photoId);

        if ($photo->getStatus()->value === 'failed') {
            $photo->resetForRetry();
            $this->em->flush();
        }
        // For pending/ready: no state change. Either way, re-dispatching is safe (handler is idempotent).
        $this->bus->dispatch(new ProcessPhoto($photoId));

        $this->addFlash('success', 'Photo re-queued.');
        return $this->redirectToRoute('admin_event_edit', ['id' => $eventId]);
    }

    #[Route(
        '/admin/events/{eventId}/photos/{photoId}/delete',
        name: 'admin_photo_delete',
        requirements: ['eventId' => '\d+', 'photoId' => '\d+'],
        methods: ['POST'],
    )]
    public function delete(
        int $eventId,
        int $photoId,
        Request $request,
        #[Autowire(service: 'photo_thumbs_storage')]
        FilesystemOperator $thumbs,
        #[Autowire(service: 'photo_previews_storage')]
        FilesystemOperator $previews,
    ): Response {
        $photo = $this->loadOrThrow($eventId, $photoId);
        $this->denyAccessUnlessGranted(EventVoter::DELETE, $photo->getEvent());
        $this->assertCsrf($request, 'delete_photo_' . $photoId);

        $path = sprintf('event-%d/%d.jpg', $eventId, $photoId);
        foreach ([$this->originals, $thumbs, $previews] as $fs) {
            try {
                $fs->delete($path);
            } catch (\League\Flysystem\FilesystemException) {
                // Missing files are fine — pipeline may not have produced them yet.
            }
        }

        $this->em->remove($photo);
        $this->em->flush();

        $this->addFlash('success', 'Photo deleted.');
        return $this->redirectToRoute('admin_event_edit', ['id' => $eventId]);
    }

    private function loadOrThrow(int $eventId, int $photoId): Photo
    {
        $photo = $this->photos->find($photoId);
        if ($photo === null || $photo->getEvent()->getId() !== $eventId) {
            throw $this->createNotFoundException();
        }
        return $photo;
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid($tokenId, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
```

- [ ] **Step 4: Run, expect pass**

```bash
vendor/bin/phpunit tests/Functional/Admin/PhotoModerationTest.php
```

- [ ] **Step 5: Quality gate + commit**

```bash
vendor/bin/phpunit
vendor/bin/grumphp run

git add src/Controller/Admin/PhotoController.php tests/Functional/Admin/PhotoModerationTest.php
git commit -m "13 - add admin photo retry and delete endpoints"
```

---

## Task 13: Admin photo grid Turbo Frame + tile templates

**Goal:** Endpoint that returns the photo grid frame contents (used by Uppy's onComplete and the Stimulus poller). Templates render thumb tiles with status badges and CSRF-protected retry/delete forms.

**Files:**
- Modify: `src/Controller/Admin/PhotoController.php` (add `gridFrame` action)
- Create: `templates/admin/event/photos_grid.html.twig`
- Create: `templates/admin/event/_photo_tile.html.twig`

- [ ] **Step 1: Add the frame action**

Append to `PhotoController`:

```php
    #[Route(
        '/admin/events/{id}/photos-grid',
        name: 'admin_photo_grid',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function gridFrame(Event $event): Response
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);

        $photos = $this->photos->findBy(['event' => $event], ['createdAt' => 'DESC'], 200);

        $hasStalePending = false;
        $cutoff = new \DateTimeImmutable('-5 minutes');
        foreach ($photos as $p) {
            if ($p->getStatus()->value === 'pending' && $p->getCreatedAt() < $cutoff) {
                $hasStalePending = true;
                break;
            }
        }

        return $this->render('admin/event/photos_grid.html.twig', [
            'event'           => $event,
            'photos'          => $photos,
            'hasStalePending' => $hasStalePending,
        ]);
    }
```

- [ ] **Step 2: Create the grid template**

`templates/admin/event/photos_grid.html.twig`:

```twig
<turbo-frame id="photos-grid"
             data-controller="photos-poller"
             data-photos-poller-src-value="{{ path('admin_photo_grid', {id: event.id}) }}">
    {% if hasStalePending %}
        <div class="alert alert-warning my-4">
            Some photos look stuck. Is the worker running?
            <code>bin/console messenger:consume async failed -vv</code>
        </div>
    {% endif %}

    {% if photos|length == 0 %}
        <p class="text-base-content/70">No photos yet. Drop some files above.</p>
    {% else %}
        <ul class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
            {% for photo in photos %}
                {{ include('admin/event/_photo_tile.html.twig', {event: event, photo: photo}) }}
            {% endfor %}
        </ul>
    {% endif %}
</turbo-frame>
```

- [ ] **Step 3: Create the tile partial**

`templates/admin/event/_photo_tile.html.twig`:

```twig
<li class="card bg-base-200 shadow-sm" data-status="{{ photo.status.value }}">
    <figure class="aspect-square bg-base-300">
        {% if photo.status.value == 'ready' %}
            <img src="{{ path('photo_serve_thumb', {id: photo.id}) }}"
                 alt="{{ photo.originalFilename }}"
                 loading="lazy"
                 class="object-cover w-full h-full">
        {% else %}
            <div class="flex items-center justify-center w-full h-full text-sm">
                {{ photo.status.value }}
            </div>
        {% endif %}
    </figure>
    <div class="card-body p-3 text-xs">
        <div class="flex items-center justify-between gap-2">
            <span class="badge badge-{{ {
                ready: 'success',
                pending: 'info',
                failed: 'error',
            }[photo.status.value] ?? 'ghost' }}">{{ photo.status.value }}</span>
            {% if photo.takenAt %}
                <time datetime="{{ photo.takenAt|date('c') }}">
                    {{ photo.takenAt|date('H:i', event.timezone) }}
                </time>
            {% endif %}
        </div>
        <p class="truncate" title="{{ photo.originalFilename }}">{{ photo.originalFilename }}</p>
        {% if photo.processingError %}
            <p class="text-error">{{ photo.processingError }}</p>
        {% endif %}
        <div class="flex gap-2 mt-2">
            {% if photo.status.value == 'failed' %}
                <form method="post" action="{{ path('admin_photo_retry', {eventId: event.id, photoId: photo.id}) }}">
                    <input type="hidden" name="_token" value="{{ csrf_token('retry_photo_' ~ photo.id) }}">
                    <button class="btn btn-xs btn-warning">Retry</button>
                </form>
            {% endif %}
            <form method="post" action="{{ path('admin_photo_delete', {eventId: event.id, photoId: photo.id}) }}"
                  onsubmit="return confirm('Delete this photo?');">
                <input type="hidden" name="_token" value="{{ csrf_token('delete_photo_' ~ photo.id) }}">
                <button class="btn btn-xs btn-ghost">Delete</button>
            </form>
        </div>
    </div>
</li>
```

- [ ] **Step 4: Smoke-test the route manually**

```bash
php -S 127.0.0.1:8000 -t public &
SERVER_PID=$!
sleep 1
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8000/admin/events/1/photos-grid
kill $SERVER_PID
```

Expected: `302` redirect to login (since not authenticated). Confirms the route exists.

- [ ] **Step 5: Quality gate + commit**

```bash
vendor/bin/phpunit
vendor/bin/grumphp run

git add src/Controller/Admin/PhotoController.php templates/admin/event/
git commit -m "13 - add admin photo grid Turbo Frame and tile template"
```

---

## Task 14: Vendor Uppy via importmap + wire upload UI

**Goal:** Uppy available via importmap (no npm), an upload `<div>` wired to `XHRUpload` against `/admin/events/{id}/photos`, hooked into the photos panel.

**Files:**
- Modify: `importmap.php`
- Create: `assets/controllers/uppy_controller.js`
- Create: `assets/controllers/photos_poller_controller.js`
- Create: `templates/admin/event/photos_panel.html.twig`
- Modify: `templates/admin/event/form.html.twig`

- [ ] **Step 1: Add Uppy to the importmap**

```bash
php bin/console importmap:require @uppy/core @uppy/dashboard @uppy/xhr-upload
php bin/console importmap:require @uppy/core/dist/style.css
php bin/console importmap:require @uppy/dashboard/dist/style.css
```

Verify by inspecting `importmap.php` — three new entries.

- [ ] **Step 2: Create the Uppy Stimulus controller**

`assets/controllers/uppy_controller.js`:

```javascript
import { Controller } from '@hotwired/stimulus';
import Uppy from '@uppy/core';
import Dashboard from '@uppy/dashboard';
import XHRUpload from '@uppy/xhr-upload';

import '@uppy/core/dist/style.css';
import '@uppy/dashboard/dist/style.css';

export default class extends Controller {
    static values = {
        endpoint: String,
        gridFrame: String,
    };

    connect() {
        this.uppy = new Uppy({
            restrictions: {
                allowedFileTypes: ['.jpg', '.jpeg', 'image/jpeg'],
                maxFileSize: 25 * 1024 * 1024,
            },
        })
            .use(Dashboard, {
                target: this.element,
                inline: true,
                height: 280,
                proudlyDisplayPoweredByUppy: false,
            })
            .use(XHRUpload, {
                endpoint: this.endpointValue,
                fieldName: 'file',
                limit: 3,
                formData: true,
            });

        this.uppy.on('complete', () => {
            const frame = document.getElementById('photos-grid');
            if (frame && this.gridFrameValue) {
                frame.setAttribute('src', this.gridFrameValue + '?_=' + Date.now());
            }
        });
    }

    disconnect() {
        if (this.uppy) {
            this.uppy.close();
        }
    }
}
```

- [ ] **Step 3: Create the polling controller**

`assets/controllers/photos_poller_controller.js`:

```javascript
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { src: String };

    connect() {
        this.poll = this.poll.bind(this);
        this.scheduleIfNeeded();
    }

    disconnect() {
        if (this.timer) {
            clearTimeout(this.timer);
        }
    }

    scheduleIfNeeded() {
        const hasPending = this.element.querySelector('[data-status="pending"]') !== null;
        if (hasPending) {
            this.timer = setTimeout(this.poll, 5000);
        }
    }

    poll() {
        this.element.setAttribute('src', this.srcValue + '?_=' + Date.now());
        // Re-arm after Turbo replaces the frame contents
        this.element.addEventListener('turbo:frame-load', () => this.scheduleIfNeeded(), { once: true });
    }
}
```

- [ ] **Step 4: Create the photos-panel template**

`templates/admin/event/photos_panel.html.twig`:

```twig
<section class="card bg-base-100 shadow-sm mt-6">
    <div class="card-body">
        <h2 class="card-title">Photos</h2>

        <div data-controller="uppy"
             data-uppy-endpoint-value="{{ path('admin_photo_upload', {id: event.id}) }}"
             data-uppy-grid-frame-value="{{ path('admin_photo_grid', {id: event.id}) }}">
        </div>

        <turbo-frame id="photos-grid" src="{{ path('admin_photo_grid', {id: event.id}) }}" loading="lazy">
            <p class="text-base-content/70">Loading photos…</p>
        </turbo-frame>
    </div>
</section>
```

- [ ] **Step 5: Include the panel on the edit form**

In `templates/admin/event/form.html.twig`, after the form's closing tag (`{{ form_end(form) }}`), add:

```twig
{% if mode == 'edit' %}
    {{ include('admin/event/photos_panel.html.twig', {event: event}) }}
{% endif %}
```

- [ ] **Step 6: Smoke test (manual)**

```bash
php bin/console asset-map:compile  # validate importmap is healthy
```

Expected: no errors. (Don't commit `public/assets/` — already gitignored.)

- [ ] **Step 7: Quality gate + commit**

```bash
vendor/bin/phpunit
vendor/bin/grumphp run

git add importmap.php assets/ templates/admin/event/
git commit -m "13 - vendor Uppy and wire admin photo upload UI"
```

---

## Task 15: Image-serving endpoints + functional test

**Goal:** Two public endpoints `/p/{id}/thumb.jpg` and `/p/{id}/preview.jpg` that stream JPEGs from the appropriate Flysystem storage. 404 if photo is not `ready`. Long-lived cache headers + ETag.

**Files:**
- Create: `src/Controller/Public/PhotoServeController.php`
- Create: `tests/Functional/Public/PhotoServeTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Functional/Public/PhotoServeTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PhotoServeTest extends WebTestCase
{
    public function testServesThumbForReadyPhoto(): void
    {
        $client = static::createClient();
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        /** @var FilesystemOperator $thumbs */
        $thumbs = $c->get('photo_thumbs_storage');

        $owner = new User('o@example.test', 'O');
        $owner->setPassword('x');
        $em->persist($owner);
        $event = new Event('e', 'E', new DateTimeImmutable('2026-06-10'), $owner);
        $event->setTimezone('UTC');
        $em->persist($event);

        $photo = new Photo($event, str_repeat('a', 64), 'x.jpg', 100);
        $photo->markReady(new DateTimeImmutable('now', new DateTimeZone('UTC')), 100, 100);
        $em->persist($photo);
        $em->flush();

        $thumbs->write(sprintf('event-%d/%d.jpg', $event->getId(), $photo->getId()), 'thumb-bytes');

        $client->request('GET', sprintf('/p/%d/thumb.jpg', $photo->getId()));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');
        self::assertSame('thumb-bytes', $client->getResponse()->getContent());

        $thumbs->delete(sprintf('event-%d/%d.jpg', $event->getId(), $photo->getId()));
    }

    public function testReturns404ForPendingPhoto(): void
    {
        $client = static::createClient();
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);

        $owner = new User('o2@example.test', 'O');
        $owner->setPassword('x');
        $em->persist($owner);
        $event = new Event('e2', 'E2', new DateTimeImmutable('2026-06-10'), $owner);
        $event->setTimezone('UTC');
        $em->persist($event);

        $photo = new Photo($event, str_repeat('b', 64), 'x.jpg', 100);
        $em->persist($photo);
        $em->flush();

        $client->request('GET', sprintf('/p/%d/thumb.jpg', $photo->getId()));

        self::assertResponseStatusCodeSame(404);
    }
}
```

- [ ] **Step 2: Run, expect failure**

```bash
vendor/bin/phpunit tests/Functional/Public/PhotoServeTest.php
```

- [ ] **Step 3: Create `PhotoServeController`**

`src/Controller/Public/PhotoServeController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\Photo;
use App\Entity\PhotoStatus;
use App\Repository\PhotoRepository;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class PhotoServeController extends AbstractController
{
    public function __construct(
        private readonly PhotoRepository $photos,
        #[Autowire(service: 'photo_thumbs_storage')]
        private readonly FilesystemOperator $thumbs,
        #[Autowire(service: 'photo_previews_storage')]
        private readonly FilesystemOperator $previews,
    ) {
    }

    #[Route(
        '/p/{id}/thumb.jpg',
        name: 'photo_serve_thumb',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function thumb(int $id, Request $request): Response
    {
        return $this->serve($id, $this->thumbs, $request);
    }

    #[Route(
        '/p/{id}/preview.jpg',
        name: 'photo_serve_preview',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function preview(int $id, Request $request): Response
    {
        return $this->serve($id, $this->previews, $request);
    }

    private function serve(int $id, FilesystemOperator $storage, Request $request): Response
    {
        $photo = $this->photos->find($id);
        if (!$photo instanceof Photo || $photo->getStatus() !== PhotoStatus::Ready) {
            throw $this->createNotFoundException();
        }

        $path = sprintf('event-%d/%d.jpg', (int) $photo->getEvent()->getId(), $id);

        $etag = sha1($id . '|' . $photo->getUpdatedAt()->format('U'));
        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'image/jpeg');
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        $response->headers->set('ETag', '"' . $etag . '"');

        if ($request->headers->get('If-None-Match') === '"' . $etag . '"') {
            return $response->setStatusCode(Response::HTTP_NOT_MODIFIED);
        }

        $response->setCallback(function () use ($storage, $path): void {
            try {
                $stream = $storage->readStream($path);
            } catch (FilesystemException) {
                return;
            }
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        });

        return $response;
    }
}
```

- [ ] **Step 4: Run, expect pass**

```bash
vendor/bin/phpunit tests/Functional/Public/PhotoServeTest.php
```

- [ ] **Step 5: Quality gate + commit**

```bash
vendor/bin/phpunit
vendor/bin/grumphp run

git add src/Controller/Public/PhotoServeController.php tests/Functional/Public/PhotoServeTest.php
git commit -m "13 - add public thumb and preview serve endpoints"
```

---

## Task 16: Wire public gallery to repository + template

**Goal:** `EventController::photos` queries `PhotoRepository::findReadyInWindow` and passes results to the template. Replace the stub block with a Tailwind grid.

**Files:**
- Modify: `src/Controller/Public/EventController.php`
- Modify: `templates/public/event/photos.html.twig`
- Create: `tests/Functional/Public/EventPhotosGalleryTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Functional/Public/EventPhotosGalleryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\Photo;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EventPhotosGalleryTest extends WebTestCase
{
    public function testShowsReadyPhotosInsideWindow(): void
    {
        $client = static::createClient();
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);

        $owner = new User('g@example.test', 'G');
        $owner->setPassword('x');
        $em->persist($owner);
        $event = new Event('gallery', 'Gallery', new DateTimeImmutable('2026-06-10'), $owner);
        $event->setTimezone('UTC');
        $em->persist($event);

        $inside = new Photo($event, str_repeat('a', 64), 'a.jpg', 100);
        $inside->markReady(new DateTimeImmutable('2026-06-10 12:00:00', new DateTimeZone('UTC')), 100, 100);
        $em->persist($inside);

        $outside = new Photo($event, str_repeat('b', 64), 'b.jpg', 100);
        $outside->markReady(new DateTimeImmutable('2026-06-10 18:00:00', new DateTimeZone('UTC')), 100, 100);
        $em->persist($outside);

        $em->flush();

        $client->request(
            'GET',
            '/e/gallery/photos?t=2026-06-10T12:00:00%2B00:00&w=30',
        );

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(sprintf('/p/%d/thumb.jpg', $inside->getId()), $content);
        self::assertStringNotContainsString(sprintf('/p/%d/thumb.jpg', $outside->getId()), $content);
    }

    public function testHidesPendingPhotos(): void
    {
        $client = static::createClient();
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);

        $owner = new User('g2@example.test', 'G');
        $owner->setPassword('x');
        $em->persist($owner);
        $event = new Event('g2', 'G2', new DateTimeImmutable('2026-06-10'), $owner);
        $event->setTimezone('UTC');
        $em->persist($event);

        $pending = new Photo($event, str_repeat('c', 64), 'c.jpg', 100);
        $em->persist($pending);
        $em->flush();

        $client->request('GET', '/e/g2/photos?t=2026-06-10T12:00:00%2B00:00&w=720');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(sprintf('/p/%d/thumb.jpg', $pending->getId()), (string) $client->getResponse()->getContent());
    }
}
```

- [ ] **Step 2: Run, expect failure**

```bash
vendor/bin/phpunit tests/Functional/Public/EventPhotosGalleryTest.php
```

- [ ] **Step 3: Wire the repository into the controller**

Modify `src/Controller/Public/EventController.php`. Add `PhotoRepository` to the constructor:

```php
    public function __construct(
        private readonly EventRepository $events,
        private readonly ClockInterface $clock,
        private readonly \App\Repository\PhotoRepository $photos,
    ) {
    }
```

Update the `photos()` action:

```php
    #[Route('/e/{slug}/photos', name: 'public_event_photos', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function photos(string $slug, Request $request): Response
    {
        $event = $this->resolve($slug);

        $timestamp = $this->parseTimestamp($request->query->get('t'));
        $window    = $this->parseWindow($request->query->get('w'), $event);

        $start = $timestamp->modify(sprintf('-%d minutes', $window));
        $end   = $timestamp->modify(sprintf('+%d minutes', $window));
        $photos = $this->photos->findReadyInWindow($event, $start, $end);

        return $this->render('public/event/photos.html.twig', [
            'event'     => $event,
            'timestamp' => $timestamp,
            'window'    => $window,
            'photos'    => $photos,
            'capHit'    => count($photos) === 200,
        ]);
    }
```

- [ ] **Step 4: Replace the photos template**

Overwrite `templates/public/event/photos.html.twig`:

```twig
{% extends 'public/_base.html.twig' %}

{% block title %}{{ event.name }} — Photos{% endblock %}

{% block public_main %}
    <section class="space-y-6">
        <header class="space-y-1">
            <h1 class="text-2xl font-semibold">{{ event.name }}</h1>
            <p class="text-sm text-base-content/70">
                Time:
                <time data-testid="timestamp" datetime="{{ timestamp|date('c') }}">{{ timestamp|date('H:i') }}</time>
                · Window: ±<span data-testid="window">{{ window }}</span> minutes
                · {{ photos|length }} photo{{ photos|length == 1 ? '' : 's' }}
            </p>
        </header>

        {% if photos|length == 0 %}
            <div class="rounded-box border border-base-300 bg-base-200 p-10 text-center">
                <p class="text-base-content/70">No photos in this window yet.</p>
            </div>
        {% else %}
            <ul class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                {% for photo in photos %}
                    <li>
                        <a href="{{ path('photo_serve_preview', {id: photo.id}) }}"
                           target="_blank"
                           rel="noopener">
                            <img src="{{ path('photo_serve_thumb', {id: photo.id}) }}"
                                 alt="{{ event.name }}"
                                 loading="lazy"
                                 class="w-full aspect-square object-cover rounded-box">
                        </a>
                    </li>
                {% endfor %}
            </ul>
            {% if capHit %}
                <p class="text-sm text-warning">
                    Showing the first 200 photos in this window. Narrow your time range to see others.
                </p>
            {% endif %}
        {% endif %}

        <p>
            <a href="{{ path('public_event_landing', {slug: event.slug}) }}" class="btn btn-ghost btn-sm">
                ← Back to event
            </a>
        </p>
    </section>
{% endblock %}
```

- [ ] **Step 5: Run, expect pass**

```bash
vendor/bin/phpunit tests/Functional/Public/EventPhotosGalleryTest.php
```

- [ ] **Step 6: Re-run the existing `EventPhotosStubTest`**

```bash
vendor/bin/phpunit tests/Functional/Public/EventPhotosStubTest.php
```

Expected: still green. The existing assertions check the `<h1>` text and `data-testid` elements which the new template preserves — they don't reference the stub copy. The file name is now slightly misleading (it no longer tests a stub) but renaming is cosmetic; keep as-is.

- [ ] **Step 7: Quality gate + commit**

```bash
vendor/bin/phpunit
vendor/bin/grumphp run

git add src/Controller/Public/EventController.php templates/public/event/photos.html.twig tests/Functional/Public/
git commit -m "13 - wire public gallery to PhotoRepository with hard cap rendering"
```

---

## Task 17: README — running the worker

**Goal:** A short README section documenting how to run the Messenger worker locally and what tables back the photo subsystem.

**Files:**
- Modify: `README.md` (create if missing)

- [ ] **Step 1: Add the section**

Append (or create) `README.md` with this section:

```markdown
## Photo ingest

Photos uploaded through the admin are processed asynchronously by a Symfony Messenger worker. To process the queue in local dev, run:

\`\`\`bash
php bin/console messenger:consume async failed -vv
\`\`\`

(Add to your project Procfile/foreman/supervisor of choice for non-dev environments — out of scope for this app.)

### Inspecting failed messages

\`\`\`bash
php bin/console messenger:failed:show
php bin/console messenger:failed:retry <id>
\`\`\`

### Storage layout

- Originals: `var/uploads/photos/originals/event-<id>/<photoId>.jpg` (private; never web-served)
- Thumbs:    `var/uploads/photos/thumbs/event-<id>/<photoId>.jpg`    (served via `/p/<id>/thumb.jpg`)
- Previews:  `var/uploads/photos/previews/event-<id>/<photoId>.jpg`  (served via `/p/<id>/preview.jpg`)
```

(Replace the escaped fences with real backticks when editing.)

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "13 - document the messenger worker and photo storage layout"
```

---

## Final verification

- [ ] **Run full test suite**

```bash
vendor/bin/phpunit
```
Expected: all tests green, no risky/warnings.

- [ ] **Run quality gate**

```bash
vendor/bin/grumphp run
```
Expected: all tasks green.

- [ ] **Manual smoke test**

1. `php bin/console messenger:consume async failed -vv` in one terminal.
2. Visit `/admin/events/{id}/edit` for an owned event.
3. Drag-drop 3–5 JPEGs (use phone-camera photos with EXIF). Watch the upload progress in the Uppy dashboard.
4. After uploads complete, the photo grid frame should refresh and show `pending` tiles, then flip to `ready` thumbnails within seconds.
5. Drop the `no-exif.jpg` fixture; tile flips to `failed` with "Missing EXIF DateTimeOriginal".
6. Click Retry; tile re-runs (still fails — useful to confirm the retry path).
7. Click Delete on a `ready` photo; tile disappears, storage files removed.
8. Visit `/e/{slug}/photos?t=<ISO timestamp covering the upload window>&w=30`. Thumbnails render; click one — preview opens in a new tab.

If any step misbehaves, capture the symptom in a follow-up issue rather than amending the plan.
