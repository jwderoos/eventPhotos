# Event Banner / Hero Image Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an organizer upload a wide hero image per event, shown full-bleed below the branded header on the public landing.

**Architecture:** Upload is handled synchronously in the event-edit/new form (no Vich, no Messenger). A shared `GdImageResizer` — extracted from the existing `DerivativeGenerator` — normalizes the upload to a single bounded JPEG derivative, which a new `BannerUploader` service writes to a dedicated `event_banners_storage` disk and records on two new `Event` columns. A public route streams the derivative with immutable cache headers + ETag; `_base.html.twig` renders it as a full-bleed hero.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3, Flysystem, GD, Twig/Tailwind, PHPUnit 13.

## Global Constraints

- **Attributes, never annotations** (mapping, routes, validation).
- **No hand-written migrations** — generate via `bin/console doctrine:migrations:diff`; edit only `getDescription()`.
- **Run PHP/Composer/`bin/console`/`vendor/bin/*` on the host** (PHP 8.5 via Homebrew). Docker only for the runtime stack.
- **GrumPHP gates must stay green:** phpstan level 10 (src, tests, public), phpcs PSR-12, phpmnd (no magic numbers in `src/` — use named constants), phpcpd (50-line / 100-token duplication), rector, `doctrine:schema:validate`.
- **Branch:** work on `feature/93-event-banner-hero` (branch name must match `^(feature|hotfix|bugfix|release)/\d+-`; `main` is blacklisted for direct commits).
- **Commits:** Claude does not run `git commit`. Each "Commit" step below means: **stage the listed files and surface the proposed commit message**; the user performs the commit. Every commit message must contain the issue number `93`.
- **Injecting a specific storage:** there are multiple `FilesystemOperator` services — always use `#[Autowire(service: '<disk>')]`, never plain autowiring.
- **Tests fail on any deprecation/notice/warning** (PHPUnit `failOnDeprecation`/`Notice`/`Warning` = true).
- Reuse the existing image fixture `tests/fixtures/photos/bigger.jpg` (3000×2000 JPEG) for image tests.

---

### Task 1: Extract `GdImageResizer` from `DerivativeGenerator`

Pure refactor, no behavior change. Moves the GD decode/scale/encode logic into a reusable, storage-agnostic service so the banner path can share it (and phpcpd stays green).

**Files:**
- Create: `src/Service/Image/GdImageResizer.php`
- Create: `tests/Unit/Service/Image/GdImageResizerTest.php`
- Modify: `src/Service/Photo/DerivativeGenerator.php`
- Modify: `tests/Unit/Service/Photo/DerivativeGeneratorTest.php` (constructor now takes a resizer)

**Interfaces:**
- Produces:
  - `GdImageResizer::decode(string $bytes): \GdImage` — throws `\RuntimeException` on undecodable input.
  - `GdImageResizer::scaleTo(\GdImage $source, int $srcW, int $srcH, int $longEdge): \GdImage` — scales preserving aspect; re-encodes a native-size copy when the source is already smaller than `$longEdge`.
  - `GdImageResizer::encode(\GdImage $image, int $quality): string` — returns JPEG bytes; throws `\RuntimeException` on empty output.

- [ ] **Step 1: Write the failing test for `GdImageResizer`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Image;

use App\Service\Image\GdImageResizer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GdImageResizerTest extends TestCase
{
    public function testDecodeScaleEncodeRoundTripBoundsLongEdgeAndPreservesAspect(): void
    {
        $resizer = new GdImageResizer();
        $bytes   = (string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/photos/bigger.jpg');

        $image = $resizer->decode($bytes);
        self::assertSame(3000, imagesx($image));
        self::assertSame(2000, imagesy($image));

        $scaled = $resizer->scaleTo($image, imagesx($image), imagesy($image), 1600);
        self::assertSame(1600, max(imagesx($scaled), imagesy($scaled)));
        // 3000x2000 -> 1600x1067 (aspect preserved)
        self::assertSame(1067, min(imagesx($scaled), imagesy($scaled)));

        $jpeg = $resizer->encode($scaled, 85);
        $dims = getimagesizefromstring($jpeg);
        self::assertNotFalse($dims);
        self::assertSame(1600, max($dims[0], $dims[1]));
    }

    public function testDecodeThrowsOnGarbage(): void
    {
        $this->expectException(RuntimeException::class);
        (new GdImageResizer())->decode('not an image');
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/Image/GdImageResizerTest.php`
Expected: FAIL — `Class "App\Service\Image\GdImageResizer" not found`.

- [ ] **Step 3: Create `GdImageResizer` (move the logic verbatim)**

```php
<?php

declare(strict_types=1);

namespace App\Service\Image;

use GdImage;
use RuntimeException;

final class GdImageResizer
{
    public function decode(string $bytes): GdImage
    {
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw new RuntimeException('Could not decode image bytes.');
        }

        return $image;
    }

    /**
     * @param int<1,max> $srcW
     * @param int<1,max> $srcH
     * @param int<1,max> $longEdge
     */
    public function scaleTo(GdImage $source, int $srcW, int $srcH, int $longEdge): GdImage
    {
        $longest = max($srcW, $srcH);
        if ($longest <= $longEdge) {
            // Source is already smaller than the target — re-encode a copy at native size.
            $copy = imagecreatetruecolor($srcW, $srcH);
            imagecopy($copy, $source, 0, 0, 0, 0, $srcW, $srcH);

            return $copy;
        }

        $ratio = $longEdge / $longest;
        $dstW  = (int) round($srcW * $ratio);
        $dstH  = (int) round($srcH * $ratio);

        $scaled = @imagescale($source, $dstW, $dstH, IMG_BICUBIC);
        if ($scaled === false) {
            // Fallback to default mode (some PHP builds reject IMG_BICUBIC).
            $scaled = imagescale($source, $dstW, $dstH);
        }

        if ($scaled === false) {
            throw new RuntimeException('imagescale failed.');
        }

        return $scaled;
    }

    public function encode(GdImage $image, int $quality): string
    {
        ob_start();
        imagejpeg($image, null, $quality);
        $bytes = ob_get_clean();

        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('imagejpeg produced no output.');
        }

        return $bytes;
    }
}
```

- [ ] **Step 4: Run the resizer test and confirm it passes**

Run: `vendor/bin/phpunit tests/Unit/Service/Image/GdImageResizerTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Refactor `DerivativeGenerator` to delegate to the resizer**

Replace the class body of `src/Service/Photo/DerivativeGenerator.php` so it injects `GdImageResizer` and drops its private `scaleTo`/`encode` methods and the local decode:

```php
<?php

declare(strict_types=1);

namespace App\Service\Photo;

use App\Service\Image\GdImageResizer;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class DerivativeGenerator
{
    private const int THUMB_LONG_EDGE   = 400;

    private const int THUMB_QUALITY     = 80;

    private const int PREVIEW_LONG_EDGE = 1600;

    private const int PREVIEW_QUALITY   = 85;

    public function __construct(
        #[Autowire(service: 'photo_originals_storage')]
        private FilesystemOperator $originals,
        #[Autowire(service: 'photo_thumbs_storage')]
        private FilesystemOperator $thumbs,
        #[Autowire(service: 'photo_previews_storage')]
        private FilesystemOperator $previews,
        private GdImageResizer $resizer,
    ) {
    }

    /**
     * @return array{0:int,1:int,2:int} [width, height, derivativeBytes]
     *                                   — derivativeBytes is the sum of thumb + preview JPEG payload sizes.
     */
    public function generate(string $path): array
    {
        $image = $this->resizer->decode($this->originals->read($path));

        $width  = imagesx($image);
        $height = imagesy($image);

        $thumbBytes   = $this->resizer->encode(
            $this->resizer->scaleTo($image, $width, $height, self::THUMB_LONG_EDGE),
            self::THUMB_QUALITY,
        );
        $previewBytes = $this->resizer->encode(
            $this->resizer->scaleTo($image, $width, $height, self::PREVIEW_LONG_EDGE),
            self::PREVIEW_QUALITY,
        );

        $this->thumbs->write($path, $thumbBytes);
        $this->previews->write($path, $previewBytes);

        return [$width, $height, strlen($thumbBytes) + strlen($previewBytes)];
    }
}
```

- [ ] **Step 6: Update `DerivativeGeneratorTest` to pass a resizer**

In `tests/Unit/Service/Photo/DerivativeGeneratorTest.php`, add `use App\Service\Image\GdImageResizer;` and change the constructor call:

```php
$generator = new DerivativeGenerator($originalsFs, $thumbsFs, $previewsFs, new GdImageResizer());
```

- [ ] **Step 7: Run both unit tests and confirm they pass**

Run: `vendor/bin/phpunit tests/Unit/Service/Image/GdImageResizerTest.php tests/Unit/Service/Photo/DerivativeGeneratorTest.php`
Expected: PASS — the `DerivativeGenerator` test still asserts 3000×2000 and long edges 400 / 1600 (behavior unchanged).

- [ ] **Step 8: Commit**

Stage `src/Service/Image/GdImageResizer.php`, `src/Service/Photo/DerivativeGenerator.php`, `tests/Unit/Service/Image/GdImageResizerTest.php`, `tests/Unit/Service/Photo/DerivativeGeneratorTest.php`. Proposed message:
`93 - extract GdImageResizer from DerivativeGenerator (no behavior change)`

---

### Task 2: Banner storage disk + `Event` columns + migration

**Files:**
- Modify: `config/packages/flysystem.yaml`
- Modify: `src/Entity/Event.php`
- Create: migration under `migrations/` (generated)
- Create: `tests/Unit/Entity/EventBannerFieldsTest.php`

**Interfaces:**
- Produces:
  - Flysystem service `event_banners_storage`.
  - `Event::getBannerFilename(): ?string` / `setBannerFilename(?string): void`
  - `Event::getBannerUpdatedAt(): ?\DateTimeImmutable` / `setBannerUpdatedAt(?\DateTimeImmutable): void`

- [ ] **Step 1: Write the failing entity test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class EventBannerFieldsTest extends TestCase
{
    public function testBannerFieldsDefaultNullAndAreSettable(): void
    {
        $owner = new User('o@example.com', 'O');
        $owner->setPassword('x');

        $event = new Event(
            'banner-fields',
            'Banner Fields',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );

        self::assertNull($event->getBannerFilename());
        self::assertNull($event->getBannerUpdatedAt());

        $stamp = new DateTimeImmutable('2026-07-07 12:00');
        $event->setBannerFilename('event-1.jpg');
        $event->setBannerUpdatedAt($stamp);

        self::assertSame('event-1.jpg', $event->getBannerFilename());
        self::assertSame($stamp, $event->getBannerUpdatedAt());
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `vendor/bin/phpunit tests/Unit/Entity/EventBannerFieldsTest.php`
Expected: FAIL — `Call to undefined method App\Entity\Event::getBannerFilename()`.

- [ ] **Step 3: Add the two columns + accessors to `Event`**

In `src/Entity/Event.php`, next to the `logoFilename` / `logoUpdatedAt` fields (around line 51-55), add:

```php
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $bannerFilename = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $bannerUpdatedAt = null;
```

And add the accessors near `getLogoFilename()` (around line 251):

```php
    public function getBannerFilename(): ?string
    {
        return $this->bannerFilename;
    }

    public function setBannerFilename(?string $bannerFilename): void
    {
        $this->bannerFilename = $bannerFilename;
    }

    public function getBannerUpdatedAt(): ?DateTimeImmutable
    {
        return $this->bannerUpdatedAt;
    }

    public function setBannerUpdatedAt(?DateTimeImmutable $bannerUpdatedAt): void
    {
        $this->bannerUpdatedAt = $bannerUpdatedAt;
    }
```

(`Types` and `DateTimeImmutable` are already imported in `Event.php`.)

- [ ] **Step 4: Add the storage disk**

In `config/packages/flysystem.yaml`, add under `storages:` (mirroring `event_logos_storage`):

```yaml
        event_banners_storage:
            adapter: 'local'
            options:
                directory: '%kernel.project_dir%/var/uploads/event-banners'
```

- [ ] **Step 5: Generate the migration**

Run:
```bash
bin/console doctrine:migrations:diff
```
Expected: a new `migrations/VersionYYYYMMDDHHMMSS.php` adding `banner_filename` + `banner_updated_at` to `events`. Edit only `getDescription()` to: `Add banner_filename and banner_updated_at to events (#93)`. Do NOT hand-edit the SQL.

- [ ] **Step 6: Apply the migration (dev + test DBs)**

Run:
```bash
bin/console doctrine:migrations:migrate --no-interaction
bin/console doctrine:database:create --env=test --if-not-exists
bin/console doctrine:migrations:migrate --no-interaction --env=test
```

- [ ] **Step 7: Verify schema + entity test**

Run:
```bash
bin/console doctrine:schema:validate
vendor/bin/phpunit tests/Unit/Entity/EventBannerFieldsTest.php
```
Expected: schema mapping + database in sync; test PASS.

- [ ] **Step 8: Commit**

Stage `config/packages/flysystem.yaml`, `src/Entity/Event.php`, the new `migrations/Version*.php`, `tests/Unit/Entity/EventBannerFieldsTest.php`. Proposed message:
`93 - add event banner columns + event_banners_storage disk`

---

### Task 3: `BannerUploader` service

**Files:**
- Create: `src/Service/Event/BannerUploader.php`
- Create: `tests/Unit/Service/Event/BannerUploaderTest.php`

**Interfaces:**
- Consumes: `GdImageResizer` (Task 1); `event_banners_storage` + `Event` banner accessors (Task 2).
- Produces:
  - `BannerUploader::upload(Event $event, string $bytes): void` — normalizes `$bytes` to a bounded JPEG, writes it to `event-<id>.jpg` on `event_banners_storage`, sets `bannerFilename` + `bannerUpdatedAt` (from the clock). Throws `\RuntimeException` if `$bytes` is not a decodable image.
  - `BannerUploader::remove(Event $event): void` — deletes the stored file if present, nulls both fields.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Event;

use App\Entity\Event;
use App\Entity\User;
use App\Service\Event\BannerUploader;
use App\Service\Image\GdImageResizer;
use DateTimeImmutable;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

final class BannerUploaderTest extends TestCase
{
    private function makeEvent(): Event
    {
        $owner = new User('owner@example.com', 'Owner');
        $owner->setPassword('x');

        return new Event(
            'uploader-slug',
            'Uploader Event',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );
    }

    public function testUploadWritesDerivativeAndStampsFields(): void
    {
        $storage = new Filesystem(new InMemoryFilesystemAdapter());
        $clock   = new MockClock('2026-07-07 12:00:00');
        $uploader = new BannerUploader(new GdImageResizer(), $storage, $clock);

        $event = $this->makeEvent();
        $bytes = (string) file_get_contents(dirname(__DIR__, 4) . '/fixtures/photos/bigger.jpg');

        $uploader->upload($event, $bytes);

        $filename = $event->getBannerFilename();
        self::assertNotNull($filename);
        self::assertTrue($storage->fileExists($filename));
        self::assertEquals($clock->now(), $event->getBannerUpdatedAt());

        // Stored file is a bounded JPEG (long edge <= 1600).
        $dims = getimagesizefromstring($storage->read($filename));
        self::assertNotFalse($dims);
        self::assertSame(1600, max($dims[0], $dims[1]));
    }

    public function testUploadRejectsNonImage(): void
    {
        $uploader = new BannerUploader(
            new GdImageResizer(),
            new Filesystem(new InMemoryFilesystemAdapter()),
            new MockClock('2026-07-07 12:00:00'),
        );

        $this->expectException(RuntimeException::class);
        $uploader->upload($this->makeEvent(), 'not an image');
    }

    public function testRemoveDeletesFileAndNullsFields(): void
    {
        $storage  = new Filesystem(new InMemoryFilesystemAdapter());
        $uploader = new BannerUploader(new GdImageResizer(), $storage, new MockClock('2026-07-07 12:00:00'));

        $event = $this->makeEvent();
        $uploader->upload($event, (string) file_get_contents(dirname(__DIR__, 4) . '/fixtures/photos/bigger.jpg'));
        $filename = (string) $event->getBannerFilename();
        self::assertTrue($storage->fileExists($filename));

        $uploader->remove($event);

        self::assertFalse($storage->fileExists($filename));
        self::assertNull($event->getBannerFilename());
        self::assertNull($event->getBannerUpdatedAt());
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/Event/BannerUploaderTest.php`
Expected: FAIL — `Class "App\Service\Event\BannerUploader" not found`.

- [ ] **Step 3: Implement `BannerUploader`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Event;

use App\Entity\Event;
use App\Service\Image\GdImageResizer;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class BannerUploader
{
    private const int LONG_EDGE = 1600;

    private const int QUALITY = 85;

    public function __construct(
        private GdImageResizer $resizer,
        #[Autowire(service: 'event_banners_storage')]
        private FilesystemOperator $banners,
        private ClockInterface $clock,
    ) {
    }

    public function upload(Event $event, string $bytes): void
    {
        $image  = $this->resizer->decode($bytes);
        $scaled = $this->resizer->scaleTo($image, imagesx($image), imagesy($image), self::LONG_EDGE);
        $jpeg   = $this->resizer->encode($scaled, self::QUALITY);

        $filename = $this->filename($event);
        $this->banners->write($filename, $jpeg);

        $event->setBannerFilename($filename);
        $event->setBannerUpdatedAt($this->clock->now());
    }

    public function remove(Event $event): void
    {
        $filename = $event->getBannerFilename();
        if ($filename !== null) {
            try {
                $this->banners->delete($filename);
            } catch (FilesystemException) {
                // Already gone — nothing to clean up.
            }
        }

        $event->setBannerFilename(null);
        $event->setBannerUpdatedAt(null);
    }

    private function filename(Event $event): string
    {
        return sprintf('event-%d.jpg', (int) $event->getId());
    }
}
```

- [ ] **Step 4: Run it and confirm it passes**

Run: `vendor/bin/phpunit tests/Unit/Service/Event/BannerUploaderTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

Stage `src/Service/Event/BannerUploader.php`, `tests/Unit/Service/Event/BannerUploaderTest.php`. Proposed message:
`93 - add BannerUploader service (sync resize + store/remove)`

---

### Task 4: Form fields + admin controller wiring

**Files:**
- Modify: `src/Form/EventType.php`
- Modify: `src/Controller/Admin/EventController.php`
- Create: `tests/Functional/Admin/EventBannerUploadTest.php`

**Interfaces:**
- Consumes: `BannerUploader` (Task 3); form fields `bannerFile` (unmapped `FileType`) and `removeBanner` (unmapped `CheckboxType`).

- [ ] **Step 1: Write the failing functional test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EventBannerUploadTest extends WebTestCase
{
    public function testOwnerUploadsBannerThenRemovesIt(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $c = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);
        /** @var FilesystemOperator $storage */
        $storage = $c->get('event_banners_storage');

        $alice = new User('banner-alice@example.com', 'Alice');
        $alice->addRole('ROLE_ORGANIZER');
        $alice->setPassword($hasher->hashPassword($alice, 'pw'));

        $event = new Event(
            'banner-fest',
            'Banner Fest',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $alice,
        );

        $em->persist($alice);
        $em->persist($event);
        $em->flush();
        $eventId = (int) $event->getId();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', $eventId));
        self::assertResponseIsSuccessful();

        // Upload a banner.
        $form = $crawler->selectButton('Save')->form();
        /** @phpstan-ignore-next-line method.nonObject */
        $form['event[bannerFile]']->upload(dirname(__DIR__, 2) . '/fixtures/photos/bigger.jpg');
        $client->submit($form);
        self::assertResponseRedirects('/admin/events');

        $em->clear();
        $reloaded = $em->find(Event::class, $eventId);
        self::assertInstanceOf(Event::class, $reloaded);
        $filename = $reloaded->getBannerFilename();
        self::assertNotNull($filename);
        self::assertTrue($storage->fileExists($filename));

        // Now remove it.
        $crawler = $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', $eventId));
        $form = $crawler->selectButton('Save')->form();
        $form['event[removeBanner]']->tick();
        $client->submit($form);
        self::assertResponseRedirects('/admin/events');

        $em->clear();
        $reloaded = $em->find(Event::class, $eventId);
        self::assertInstanceOf(Event::class, $reloaded);
        self::assertNull($reloaded->getBannerFilename());
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `vendor/bin/phpunit tests/Functional/Admin/EventBannerUploadTest.php`
Expected: FAIL — the `event[bannerFile]` field does not exist on the form.

- [ ] **Step 3: Add the form fields**

In `src/Form/EventType.php`, add the import `use Symfony\Component\Form\Extension\Core\Type\FileType;` and, immediately after the existing `logoFile` block (around line 85-90), add:

```php
        $builder->add('bannerFile', FileType::class, [
            'mapped'      => false,
            'required'    => false,
            'label'       => 'Banner / hero image (JPEG or PNG, max 5 MB — recommended 1200×400, 3:1)',
            'constraints' => [
                new Assert\File(
                    maxSize: '5M',
                    mimeTypes: ['image/jpeg', 'image/png'],
                    mimeTypesMessage: 'Upload a JPEG or PNG image.',
                ),
            ],
        ]);

        $builder->add('removeBanner', CheckboxType::class, [
            'mapped'   => false,
            'required' => false,
            'label'    => 'Remove current banner',
        ]);
```

(`Assert` and `CheckboxType` are already imported.)

- [ ] **Step 4: Wire the controller**

In `src/Controller/Admin/EventController.php`:

Add imports:
```php
use App\Service\Event\BannerUploader;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
```

Add a constructor dependency (in the promoted-property list):
```php
        private readonly BannerUploader $bannerUploader,
```

In the `new()` action, replace the success block's flush so the banner is applied after the id exists:
```php
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($event);
            $this->em->flush();

            $this->applyBanner($form, $event);
            $this->em->flush();

            $this->audit->set('created_id', $event->getId());
            $this->audit->targetLabel($event->getName() . ' (' . $event->getSlug() . ')');

            $this->addFlash('success', 'Event created.');

            return $this->redirectToRoute('admin_event_index');
        }
```

In the `edit()` action, apply the banner before the existing flush (the event already has an id):
```php
        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyBanner($form, $event);
            $this->audit->targetLabel($event->getName() . ' (' . $event->getSlug() . ')');
            $this->em->flush();
            $this->addFlash('success', 'Event updated.');

            return $this->redirectToRoute('admin_event_index');
        }
```

Add the private helper (place it near the other private methods in the class):
```php
    private function applyBanner(FormInterface $form, Event $event): void
    {
        if ($form->get('removeBanner')->getData() === true) {
            $this->bannerUploader->remove($event);

            return;
        }

        $file = $form->get('bannerFile')->getData();
        if ($file instanceof UploadedFile) {
            $this->bannerUploader->upload($event, (string) file_get_contents($file->getPathname()));
        }
    }
```

- [ ] **Step 5: Render the form field in the edit/new template**

Confirm the banner fields render. Open `templates/admin/event/form.html.twig`; if it renders fields explicitly (not `form_rest`), add near the `logoFile` row:
```twig
                {{ form_row(form.bannerFile) }}
                {{ form_row(form.removeBanner) }}
```
If the template already emits `{{ form_rest(form) }}`, the fields render automatically — no change needed. (Grep first: `grep -n "logoFile\|form_rest" templates/admin/event/form.html.twig`.)

- [ ] **Step 6: Run the functional test and confirm it passes**

Run: `vendor/bin/phpunit tests/Functional/Admin/EventBannerUploadTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

Stage `src/Form/EventType.php`, `src/Controller/Admin/EventController.php`, `templates/admin/event/form.html.twig` (if changed), `tests/Functional/Admin/EventBannerUploadTest.php`. Proposed message:
`93 - wire banner upload/remove into event form + admin controller`

---

### Task 5: Public serve route (with shared cache helper)

**Files:**
- Modify: `src/Controller/Public/EventController.php`
- Create: `tests/Functional/Public/EventBannerServeTest.php`

**Interfaces:**
- Consumes: `Event` banner accessors (Task 2); `event_banners_storage`.
- Produces: route `public_event_banner` → `GET /e/{slug}/banner.jpg`; private helper `serveCachedFile(...)` reused by `brandLogo` and `banner`.

- [ ] **Step 1: Write the failing functional test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EventBannerServeTest extends WebTestCase
{
    private function persistEventWithBanner(EntityManagerInterface $em, FilesystemOperator $storage, string $slug, bool $withBanner): Event
    {
        $owner = new User($slug . '@example.com', 'Owner');
        $owner->setPassword('x');

        $event = new Event(
            $slug,
            'Banner Serve',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );

        if ($withBanner) {
            $filename = 'event-serve.jpg';
            $storage->write($filename, (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/photos/bigger.jpg'));
            $event->setBannerFilename($filename);
            $event->setBannerUpdatedAt(new DateTimeImmutable('2026-07-07 12:00'));
        }

        $em->persist($owner);
        $em->persist($event);
        $em->flush();

        return $event;
    }

    public function testServesBannerWithImmutableCacheHeaders(): void
    {
        $client = self::createClient();
        $c = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var FilesystemOperator $storage */
        $storage = $c->get('event_banners_storage');

        $this->persistEventWithBanner($em, $storage, 'banner-serve-yes', true);

        $client->request(Request::METHOD_GET, '/e/banner-serve-yes/banner.jpg');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');
        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('immutable', $cacheControl);
        self::assertStringContainsString('max-age=31536000', $cacheControl);
        self::assertNotNull($client->getResponse()->headers->get('ETag'));
    }

    public function testReturns404WhenNoBanner(): void
    {
        $client = self::createClient();
        $c = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var FilesystemOperator $storage */
        $storage = $c->get('event_banners_storage');

        $this->persistEventWithBanner($em, $storage, 'banner-serve-no', false);

        $client->request(Request::METHOD_GET, '/e/banner-serve-no/banner.jpg');

        self::assertResponseStatusCodeSame(404);
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `vendor/bin/phpunit tests/Functional/Public/EventBannerServeTest.php`
Expected: FAIL — route `/e/banner-serve-yes/banner.jpg` returns 404 (not defined).

- [ ] **Step 3: Add the storage dependency + constant**

In `src/Controller/Public/EventController.php`, add a constructor dependency:
```php
        #[Autowire(service: 'event_banners_storage')]
        private readonly FilesystemOperator $eventBannersStorage,
```
And add a constant next to `BRAND_LOGO_MAX_AGE`:
```php
    private const int BANNER_MAX_AGE = 31536000;
```

- [ ] **Step 4: Extract the shared cache helper and refactor `brandLogo`**

Add this private method to the class:
```php
    private function serveCachedFile(
        Request $request,
        FilesystemOperator $storage,
        string $filename,
        string $etagSeed,
        int $maxAge,
        string $contentType,
        bool $immutable = false,
    ): Response {
        $response = new Response();
        $response->setEtag(sha1($etagSeed));
        $response->setPublic();
        $response->setMaxAge($maxAge);
        if ($immutable) {
            $response->headers->addCacheControlDirective('immutable');
        }

        if ($response->isNotModified($request)) {
            return $response;
        }

        try {
            $contents = $storage->read($filename);
        } catch (FilesystemException) {
            throw new NotFoundHttpException();
        }

        $response->setContent($contents);
        $response->headers->set('Content-Type', $contentType);

        return $response;
    }
```

Refactor the tail of `brandLogo()` (from building `$etag` onward) to delegate:
```php
        $updatedAt = $profile->getBrandLogoUpdatedAt();
        $etagSeed  = sprintf(
            '%d|%s',
            (int) $profile->getId(),
            $updatedAt instanceof DateTimeImmutable ? $updatedAt->format('U') : '-',
        );

        return $this->serveCachedFile(
            $request,
            $this->brandLogosStorage,
            $filename,
            $etagSeed,
            self::BRAND_LOGO_MAX_AGE,
            $this->brandLogoMime($filename),
        );
```

- [ ] **Step 5: Add the banner action**

Add after `brandLogo()`:
```php
    #[Route(
        '/e/{slug}/banner.jpg',
        name: 'public_event_banner',
        requirements: ['slug' => '[a-z0-9-]+'],
        methods: ['GET'],
    )]
    public function banner(string $slug, Request $request): Response
    {
        $event    = $this->resolve($slug);
        $filename = $event->getBannerFilename();
        if ($filename === null) {
            throw new NotFoundHttpException();
        }

        $updatedAt = $event->getBannerUpdatedAt();
        $etagSeed  = sprintf(
            '%d|%s',
            (int) $event->getId(),
            $updatedAt instanceof DateTimeImmutable ? $updatedAt->format('U') : '-',
        );

        return $this->serveCachedFile(
            $request,
            $this->eventBannersStorage,
            $filename,
            $etagSeed,
            self::BANNER_MAX_AGE,
            'image/jpeg',
            true,
        );
    }
```

- [ ] **Step 6: Run the banner + brand-logo serve tests (regression) and confirm pass**

Run:
```bash
vendor/bin/phpunit tests/Functional/Public/EventBannerServeTest.php tests/Functional/Public/EventBrandLogoServeTest.php
```
Expected: PASS — banner tests green AND the brand-logo tests still pass after the helper extraction (headers unchanged: `BRAND_LOGO_MAX_AGE`, no `immutable`).

- [ ] **Step 7: Commit**

Stage `src/Controller/Public/EventController.php`, `tests/Functional/Public/EventBannerServeTest.php`. Proposed message:
`93 - serve event banner via public route with immutable cache + ETag`

---

### Task 6: Full-bleed hero rendering in `_base.html.twig`

**Files:**
- Modify: `templates/public/_base.html.twig`
- Create: `tests/Functional/Public/EventBannerRenderTest.php`

**Interfaces:**
- Consumes: `public_event_banner` route (Task 5); `Event::getBannerFilename()`.

- [ ] **Step 1: Write the failing functional test**

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

final class EventBannerRenderTest extends WebTestCase
{
    private function persist(EntityManagerInterface $em, string $slug, bool $withBanner): void
    {
        $owner = new User($slug . '@example.com', 'Owner');
        $owner->setPassword('x');

        $event = new Event(
            $slug,
            'Hero Render',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );

        if ($withBanner) {
            $event->setBannerFilename('event-render.jpg');
            $event->setBannerUpdatedAt(new DateTimeImmutable('2026-07-07 12:00'));
        }

        $em->persist($owner);
        $em->persist($event);
        $em->flush();
    }

    public function testHeroImageRendersWhenBannerSet(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->persist($em, 'hero-yes', true);

        $crawler = $client->request(Request::METHOD_GET, '/e/hero-yes');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('img[src$="/banner.jpg"]')->count());
    }

    public function testNoHeroImageWhenBannerAbsent(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->persist($em, 'hero-no', false);

        $crawler = $client->request(Request::METHOD_GET, '/e/hero-no');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('img[src$="/banner.jpg"]')->count());
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `vendor/bin/phpunit tests/Functional/Public/EventBannerRenderTest.php`
Expected: FAIL — `testHeroImageRendersWhenBannerSet` finds 0 matching `<img>` (hero not rendered yet).

- [ ] **Step 3: Add the hero block to `_base.html.twig`**

In `templates/public/_base.html.twig`, insert this block **between the closing `</header>` and the opening `<main ...>`** (it sits directly inside the full-width flex wrapper, so it spans edge-to-edge — full-bleed):

```twig
        {% if event is defined and event and event.bannerFilename %}
            <div class="w-full" data-event-hero>
                <img
                    src="{{ path('public_event_banner', {slug: event.slug}) }}"
                    alt="{{ event.name }}"
                    class="h-40 w-full object-cover sm:h-56 md:h-72"
                />
            </div>
        {% endif %}
```

- [ ] **Step 4: Run the render test and confirm it passes**

Run: `vendor/bin/phpunit tests/Functional/Public/EventBannerRenderTest.php`
Expected: PASS (2 tests). If `/e/{slug}` redirects to the photos page for the chosen display state, `followRedirects()` lands on another `_base` page that also renders the hero — the assertion still holds.

- [ ] **Step 5: Commit**

Stage `templates/public/_base.html.twig`, `tests/Functional/Public/EventBannerRenderTest.php`. Proposed message:
`93 - render full-bleed event hero below header on public pages`

---

### Task 7: Full-suite green + docs

**Files:**
- Modify: `CLAUDE.md` (optional one-liner under the storage-paths list)

- [ ] **Step 1: Run the full quality gate**

Run:
```bash
vendor/bin/grumphp run
```
Expected: all tasks green — phpstan L10, phpcs, phpmnd, phpcpd, rector, `doctrine:schema:validate`, and the full PHPUnit suite. Fix any finding at its source (no suppressions).

- [ ] **Step 2: Note the new storage disk in `CLAUDE.md`**

Under the "Storage paths" bullet list in `CLAUDE.md`, add:
```
- `event_banners_storage` → `var/uploads/event-banners/event-<id>.jpg` (public event hero; single normalized JPEG derivative, no original kept; served via `public_event_banner`)
```

- [ ] **Step 3: Commit**

Stage `CLAUDE.md`. Proposed message:
`93 - document event_banners_storage disk`

---

## Notes for the implementer

- **Fixture reuse:** all image tests use `tests/fixtures/photos/bigger.jpg` (3000×2000). The `dirname(__DIR__, N)` depth differs per test file — counts above are correct for their stated paths.
- **Why not Vich:** we store a processed derivative and discard the original, so Vich (which manages the raw upload) doesn't fit. The upload is handled manually in the controller → `BannerUploader`, mirroring `Admin\PhotoController::upload`.
- **Create-flow double flush:** in `new()`, the first flush assigns the event id (the banner filename is `event-<id>.jpg`); the banner is applied and a second flush persists the banner columns. In `edit()` the id already exists, so a single flush suffices.
- **phpcpd:** Task 5 deliberately extracts `serveCachedFile()` and routes `brandLogo` through it — do not inline a second near-identical serve body, or phpcpd will flag it.
