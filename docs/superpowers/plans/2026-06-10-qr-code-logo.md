# QR Code Logo Overlay Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an optional per-event logo that renders in the center of the generated QR code, introducing the project's first file-upload infrastructure.

**Architecture:** New nullable `logoFilename`/`logoUpdatedAt` columns on `Event`; uploads handled by `vich/uploader-bundle` on top of `league/flysystem-bundle` storage (local in dev, cloud-DSN-driven in prod). Uploaded files served through an access-controlled Symfony controller (never via `public/`). `QrCodeRenderer` gains an optional `?string $logoContents` argument; controller reads bytes from Flysystem and hands them to the renderer.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3, `vich/uploader-bundle`, `league/flysystem-bundle`, `endroid/qr-code` ^6, PHPUnit 13.

**Spec:** `docs/superpowers/specs/2026-06-10-qr-code-logo-design.md`

---

## File Structure

**Created:**
- `config/packages/flysystem.yaml` — Flysystem storage definition (local adapter in dev).
- `config/packages/vich_uploader.yaml` — Vich mapping wired to Flysystem.
- `config/packages/test/flysystem.yaml` — in-memory adapter override for tests.
- `migrations/Version20260610XXXXXX.php` — adds `logo_filename`, `logo_updated_at` columns.
- `tests/fixtures/logo.png` — tiny PNG used by both unit and functional tests.
- `tests/Functional/Admin/EventLogoUploadTest.php` — upload form coverage.

**Modified:**
- `composer.json` (+ `.lock`) — three new dependencies.
- `src/Entity/Event.php` — logo fields, `#[Vich\Uploadable]`, validation attribute.
- `src/Form/EventType.php` — one `VichFileType` field.
- `src/Service/QrCodeRenderer.php` — optional `?string $logoContents` arg, temp-file helper, conditional error correction.
- `src/Controller/Admin/EventController.php` — constructor gains `FilesystemOperator` + `LoggerInterface`; new `logo()` action; `qr()` / `qrPng()` read logo bytes.
- `templates/admin/event/form.html.twig` — logo preview thumbnail above the form widgets.
- `tests/Unit/Service/QrCodeRendererTest.php` — logo cases.
- `tests/Functional/Admin/EventQrTest.php` — three new cases.

---

## Task 1: Install dependencies

**Files:**
- Modify: `composer.json`, `composer.lock`

- [ ] **Step 1: Install runtime bundles**

Run:
```bash
composer require vich/uploader-bundle league/flysystem-bundle
```

Expected: both bundles installed, Symfony Flex recipes prompt accepted for default config files (we'll overwrite them anyway).

- [ ] **Step 2: Install in-memory Flysystem adapter for tests**

Run:
```bash
composer require --dev league/flysystem-memory
```

Expected: success.

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock symfony.lock config/bundles.php
git commit -m "feat: add vich uploader and flysystem bundles"
```

---

## Task 2: Configure Flysystem storage

**Files:**
- Create: `config/packages/flysystem.yaml`
- Create: `config/packages/test/flysystem.yaml`
- Modify: `.gitignore` (ignore `var/uploads/`)

- [ ] **Step 1: Write dev/prod Flysystem config**

Create `config/packages/flysystem.yaml`:

```yaml
flysystem:
    storages:
        event_logos_storage:
            adapter: 'local'
            options:
                directory: '%kernel.project_dir%/var/uploads/event-logos'
```

- [ ] **Step 2: Write test override using in-memory adapter**

Create `config/packages/test/flysystem.yaml`:

```yaml
flysystem:
    storages:
        event_logos_storage:
            adapter: 'memory'
```

- [ ] **Step 3: Ensure local upload directory is git-ignored**

Verify `.gitignore` contains `/var/`. If not, append:

```
/var/uploads/
```

- [ ] **Step 4: Verify config is valid by clearing the cache**

Run:
```bash
php bin/console cache:clear --env=dev
php bin/console cache:clear --env=test
```

Expected: both commands succeed with no errors mentioning flysystem.

- [ ] **Step 5: Verify the named storage service exists**

Run:
```bash
php bin/console debug:container event_logos_storage
```

Expected: lists a service of class `League\Flysystem\Filesystem` (or `FilesystemOperator`).

- [ ] **Step 6: Commit**

```bash
git add config/packages/flysystem.yaml config/packages/test/flysystem.yaml .gitignore
git commit -m "feat: configure flysystem event_logos storage"
```

---

## Task 3: Configure Vich uploader

**Files:**
- Create: `config/packages/vich_uploader.yaml`

- [ ] **Step 1: Write Vich config**

Create `config/packages/vich_uploader.yaml`:

```yaml
vich_uploader:
    db_driver: orm
    storage: flysystem
    mappings:
        event_logo:
            uri_prefix: ~
            upload_destination: event_logos_storage
            namer: Vich\UploaderBundle\Naming\SmartUniqueNamer
            delete_on_remove: true
            delete_on_update: true
```

- [ ] **Step 2: Verify config**

Run:
```bash
php bin/console cache:clear --env=dev
php bin/console debug:config vich_uploader
```

Expected: output shows the `event_logo` mapping with `storage: flysystem` and `upload_destination: event_logos_storage`.

- [ ] **Step 3: Commit**

```bash
git add config/packages/vich_uploader.yaml
git commit -m "feat: configure vich event_logo mapping on flysystem"
```

---

## Task 4: Extend `Event` entity with logo fields

**Files:**
- Modify: `src/Entity/Event.php`

- [ ] **Step 1: Add the Vich and Validator imports**

In `src/Entity/Event.php`, add to the `use` block at the top:

```php
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Annotation as Vich;
```

- [ ] **Step 2: Mark the class as Vich uploadable**

Change the class attribute block from:

```php
#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'events')]
#[ORM\UniqueConstraint(name: 'uniq_events_slug', columns: ['slug'])]
class Event implements Stringable
```

to:

```php
#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'events')]
#[ORM\UniqueConstraint(name: 'uniq_events_slug', columns: ['slug'])]
#[Vich\Uploadable]
class Event implements Stringable
```

- [ ] **Step 3: Add the three new properties**

Insert the following property declarations alongside the existing private properties (before the constructor):

```php
#[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
private ?string $logoFilename = null;

#[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
private ?DateTimeImmutable $logoUpdatedAt = null;

#[Vich\UploadableField(mapping: 'event_logo', fileNameProperty: 'logoFilename')]
#[Assert\File(
    maxSize: '2M',
    mimeTypes: ['image/png', 'image/jpeg'],
    mimeTypesMessage: 'Please upload a PNG or JPEG image.',
)]
private ?File $logoFile = null;
```

- [ ] **Step 4: Add getters and setters**

Append these methods to the class (anywhere after the existing getters, before `__toString`):

```php
public function getLogoFilename(): ?string
{
    return $this->logoFilename;
}

public function setLogoFilename(?string $logoFilename): void
{
    $this->logoFilename = $logoFilename;
}

public function getLogoUpdatedAt(): ?DateTimeImmutable
{
    return $this->logoUpdatedAt;
}

public function getLogoFile(): ?File
{
    return $this->logoFile;
}

public function setLogoFile(?File $logoFile): void
{
    $this->logoFile = $logoFile;

    if ($logoFile !== null) {
        $this->logoUpdatedAt = new DateTimeImmutable();
    }
}
```

The `setLogoFile` setter mutating `logoUpdatedAt` is the Vich-required "dirty-tracking" pattern: without a tracked property change, Doctrine would not see the entity as modified and would not persist the new `logoFilename` after Vich injects it.

- [ ] **Step 5: Verify the class still loads**

Run:
```bash
php bin/console debug:autowiring App\\Entity\\Event
```

Expected: no syntax / autoloading errors. (The command may say the entity is not autowireable — that is fine, we just want PHP to parse it.)

- [ ] **Step 6: Run PHPStan to catch type mistakes**

Run:
```bash
vendor/bin/phpstan analyse src/Entity/Event.php
```

Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add src/Entity/Event.php
git commit -m "feat: add logo fields to Event entity"
```

---

## Task 5: Generate and apply the migration

**Files:**
- Create: `migrations/Version20260610XXXXXX.php` (filename produced by Doctrine)

- [ ] **Step 1: Generate the migration**

Run:
```bash
php bin/console doctrine:migrations:diff
```

Expected: a new file in `migrations/` named `Version<timestamp>.php`. Open it.

- [ ] **Step 2: Verify the migration body**

The `up()` method should contain exactly:

```php
$this->addSql('ALTER TABLE events ADD logo_filename VARCHAR(255) DEFAULT NULL, ADD logo_updated_at DATETIME DEFAULT NULL');
```

(SQL dialect-dependent; the column types and nullability are what matters, not the exact SQL.)

If extra SQL appears (e.g. for unrelated tables), abort: that means the schema drifted, fix it before continuing.

- [ ] **Step 3: Apply the migration to the dev database**

Run:
```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

Expected: migration applied successfully.

- [ ] **Step 4: Apply the migration to the test database**

Run:
```bash
php bin/console doctrine:migrations:migrate --no-interaction --env=test
```

Expected: migration applied successfully.

- [ ] **Step 5: Commit**

```bash
git add migrations/
git commit -m "feat: migration for event logo columns"
```

---

## Task 6: Add fixture logo for tests

**Files:**
- Create: `tests/fixtures/logo.png`

- [ ] **Step 1: Generate a small valid PNG**

Run:
```bash
mkdir -p tests/fixtures
php -r 'file_put_contents("tests/fixtures/logo.png", imagepng(imagecreatetruecolor(4, 4)) ?: "" );' 2>/dev/null \
  || php -r '
    $im = imagecreatetruecolor(4, 4);
    imagepng($im, "tests/fixtures/logo.png");
  '
```

- [ ] **Step 2: Verify it is a valid PNG**

Run:
```bash
file tests/fixtures/logo.png
```

Expected: output contains `PNG image data, 4 x 4`.

- [ ] **Step 3: Commit**

```bash
git add tests/fixtures/logo.png
git commit -m "test: add 4x4 PNG fixture for logo tests"
```

---

## Task 7: Failing unit tests for renderer with logo

**Files:**
- Modify: `tests/Unit/Service/QrCodeRendererTest.php`

- [ ] **Step 1: Append three new test methods**

Append to `tests/Unit/Service/QrCodeRendererTest.php` (inside the class, after the existing tests):

```php
public function testSvgWithLogoDiffersFromSvgWithoutLogo(): void
{
    $renderer = new QrCodeRenderer();
    $url = 'https://example.com/e/summer-fest';
    $logo = (string) file_get_contents(__DIR__ . '/../../fixtures/logo.png');

    $plain = $renderer->svg($url);
    $withLogo = $renderer->svg($url, $logo);

    $this->assertStringContainsString('<svg', $withLogo);
    $this->assertNotSame($plain, $withLogo);
}

public function testPngWithLogoStartsWithPngMagicAndDiffersFromPlainPng(): void
{
    $renderer = new QrCodeRenderer();
    $url = 'https://example.com/e/summer-fest';
    $logo = (string) file_get_contents(__DIR__ . '/../../fixtures/logo.png');

    $plain = $renderer->png($url);
    $withLogo = $renderer->png($url, $logo);

    $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $withLogo);
    $this->assertNotSame($plain, $withLogo);
}

public function testRendererRejectsInvalidLogoBytes(): void
{
    $renderer = new QrCodeRenderer();

    $this->expectException(\Throwable::class);
    $renderer->svg('https://example.com/e/x', 'not-an-image');
}
```

- [ ] **Step 2: Run the new tests to confirm they fail**

Run:
```bash
vendor/bin/phpunit --filter 'QrCodeRendererTest::testSvgWithLogo|QrCodeRendererTest::testPngWithLogo|QrCodeRendererTest::testRendererRejectsInvalidLogoBytes'
```

Expected: 3 failures or errors. The first two complain about the unexpected second argument (signature mismatch); the third may pass for the wrong reason. We are about to change the signature next.

---

## Task 8: Implement logo support in `QrCodeRenderer`

**Files:**
- Modify: `src/Service/QrCodeRenderer.php`

- [ ] **Step 1: Rewrite the renderer**

Replace the entire contents of `src/Service/QrCodeRenderer.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Endroid\QrCode\Writer\WriterInterface;
use RuntimeException;

final class QrCodeRenderer
{
    private const int DEFAULT_SVG_SIZE = 320;
    private const int DEFAULT_PNG_SIZE = 512;
    private const int MARGIN = 10;
    private const float LOGO_WIDTH_RATIO = 0.20;

    public function svg(string $url, ?string $logoContents = null, ?int $size = null): string
    {
        return $this->build(new SvgWriter(), $url, $logoContents, $size ?? self::DEFAULT_SVG_SIZE);
    }

    public function png(string $url, ?string $logoContents = null, ?int $size = null): string
    {
        return $this->build(new PngWriter(), $url, $logoContents, $size ?? self::DEFAULT_PNG_SIZE);
    }

    private function build(WriterInterface $writer, string $url, ?string $logoContents, int $size): string
    {
        return $this->withTempLogo(
            $logoContents,
            function (?string $logoPath) use ($writer, $url, $logoContents, $size): string {
                $builder = new Builder(
                    writer: $writer,
                    data: $url,
                    size: $size,
                    margin: self::MARGIN,
                    errorCorrectionLevel: $logoContents !== null
                        ? ErrorCorrectionLevel::High
                        : ErrorCorrectionLevel::Medium,
                    logoPath: $logoPath,
                    logoResizeToWidth: $logoPath !== null ? (int) ($size * self::LOGO_WIDTH_RATIO) : null,
                    logoPunchoutBackground: $logoPath !== null,
                );

                return $builder->build()->getString();
            },
        );
    }

    /**
     * @param callable(?string): string $fn
     */
    private function withTempLogo(?string $logoContents, callable $fn): string
    {
        if ($logoContents === null) {
            return $fn(null);
        }

        $path = tempnam(sys_get_temp_dir(), 'qrlogo_');
        if ($path === false) {
            throw new RuntimeException('Failed to create temp file for QR logo.');
        }

        try {
            file_put_contents($path, $logoContents);
            return $fn($path);
        } finally {
            @unlink($path);
        }
    }
}
```

- [ ] **Step 2: Run the renderer's unit tests**

Run:
```bash
vendor/bin/phpunit tests/Unit/Service/QrCodeRendererTest.php
```

Expected: all tests pass, including the three new ones.

- [ ] **Step 3: Run PHPStan**

Run:
```bash
vendor/bin/phpstan analyse src/Service/QrCodeRenderer.php
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add src/Service/QrCodeRenderer.php tests/Unit/Service/QrCodeRendererTest.php
git commit -m "feat: QrCodeRenderer supports optional center logo"
```

---

## Task 9: Add the logo field to `EventType`

**Files:**
- Modify: `src/Form/EventType.php`

- [ ] **Step 1: Add the Vich form type import**

Add to the `use` block at the top of `src/Form/EventType.php`:

```php
use Vich\UploaderBundle\Form\Type\VichFileType;
```

- [ ] **Step 2: Add the `logoFile` field to the builder**

Insert the following call into the chain in `buildForm()`, right after the existing `->add('defaultWindowMinutes', ...)` block and before the `$user = ...` line:

```php
$builder->add('logoFile', VichFileType::class, [
    'required'     => false,
    'label'        => 'Logo (PNG or JPEG, max 2 MB)',
    'allow_delete' => true,
    'download_uri' => false,
    'image_uri'    => false,
]);
```

- [ ] **Step 3: Run PHPStan**

Run:
```bash
vendor/bin/phpstan analyse src/Form/EventType.php
```

Expected: no errors.

- [ ] **Step 4: Smoke-test the form by hitting the edit page**

Run (in a separate terminal if not already up):
```bash
php -S 127.0.0.1:8080 -t public &
```

Open `http://127.0.0.1:8080/admin/events/{any-existing-event-id}/edit` in a browser, log in as an organizer. The page should render with a "Logo (PNG or JPEG, max 2 MB)" file input and no PHP errors. Kill the server when done.

- [ ] **Step 5: Commit**

```bash
git add src/Form/EventType.php
git commit -m "feat: logo file upload field on event form"
```

---

## Task 10: Show the existing logo above the form

**Files:**
- Modify: `templates/admin/event/form.html.twig`

- [ ] **Step 1: Insert the preview block**

In `templates/admin/event/form.html.twig`, find the `{{ form_start(form, ...) }}` line and insert the following block immediately above the opening `<div class="card-body ...">` line that follows it. The final region of the template should read:

```twig
{{ form_start(form, {attr: {id: 'event-form', class: 'card bg-base-100 shadow-sm'}}) }}
    {% if event.logoFilename ?? false %}
        <div class="px-6 pt-6 flex items-center gap-3">
            <span class="text-sm font-medium">Current logo:</span>
            <img src="{{ path('admin_event_logo', {id: event.id}) }}"
                 alt="Event logo"
                 class="h-16 w-16 object-contain border rounded bg-base-200" />
        </div>
    {% endif %}
    <div class="card-body grid gap-4 lg:grid-cols-2">
        {{ form_widget(form) }}
    </div>
{{ form_end(form, {render_rest: false}) }}
```

The `event.logoFilename ?? false` guard handles the "new event" case where `event.id` is null and there is no filename — the preview is skipped entirely.

- [ ] **Step 2: Smoke-test**

Start the dev server (if not already running):
```bash
php -S 127.0.0.1:8080 -t public &
```

Visit `http://127.0.0.1:8080/admin/events/new`. The page must render without errors (no preview shown — there is no logo yet).

Kill the server.

- [ ] **Step 3: Commit**

```bash
git add templates/admin/event/form.html.twig
git commit -m "feat: show existing logo thumbnail on event form"
```

---

## Task 11: Failing functional test for the logo-serving route

**Files:**
- Modify: `tests/Functional/Admin/EventQrTest.php`

- [ ] **Step 1: Add a 403 test for the logo route**

Append to `tests/Functional/Admin/EventQrTest.php` (inside the class):

```php
public function testNonOwnerCannotFetchEventLogo(): void
{
    $client = self::createClient();
    $container = self::getContainer();

    /** @var EntityManagerInterface $em */
    $em = $container->get(EntityManagerInterface::class);
    /** @var UserPasswordHasherInterface $hasher */
    $hasher = $container->get(UserPasswordHasherInterface::class);

    $alice = new User('alice@example.com', 'Alice');
    $alice->addRole('ROLE_ORGANIZER');
    $alice->setPassword($hasher->hashPassword($alice, 'pw'));

    $bob = new User('bob@example.com', 'Bob');
    $bob->addRole('ROLE_ORGANIZER');
    $bob->setPassword($hasher->hashPassword($bob, 'pw'));

    $event = new Event('summer-fest', 'Summer Fest', new DateTimeImmutable('2026-07-15'), $alice);

    $em->persist($alice);
    $em->persist($bob);
    $em->persist($event);
    $em->flush();

    $client->loginUser($bob);
    $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/logo', (int) $event->getId()));

    $this->assertSame(403, $client->getResponse()->getStatusCode());
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run:
```bash
vendor/bin/phpunit --filter testNonOwnerCannotFetchEventLogo
```

Expected: failure — route does not exist yet (404 instead of 403, or a routing exception).

---

## Task 12: Add `FilesystemOperator` + `LoggerInterface` to the controller and implement the logo route

**Files:**
- Modify: `src/Controller/Admin/EventController.php`

- [ ] **Step 1: Add the new imports**

Add to the `use` block at the top of `src/Controller/Admin/EventController.php`:

```php
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
```

- [ ] **Step 2: Extend the constructor**

Replace the existing constructor block with:

```php
public function __construct(
    private readonly EventRepository $events,
    private readonly EntityManagerInterface $em,
    private readonly QrCodeRenderer $renderer,
    private readonly UrlGeneratorInterface $urlGenerator,
    #[Autowire(service: 'event_logos_storage')]
    private readonly FilesystemOperator $eventLogosStorage,
    private readonly LoggerInterface $logger,
) {
}
```

- [ ] **Step 3: Add the logo route action**

Append the following action to the controller, just above `private function eventLandingUrl(...)`:

```php
#[Route(
    '/admin/events/{id}/logo',
    name: 'admin_event_logo',
    requirements: ['id' => '\d+'],
    methods: ['GET'],
)]
public function logo(Event $event): Response
{
    $this->denyAccessUnlessGranted(EventVoter::VIEW, $event);

    $filename = $event->getLogoFilename();
    if ($filename === null) {
        throw $this->createNotFoundException();
    }

    try {
        $contents = $this->eventLogosStorage->read($filename);
    } catch (FilesystemException) {
        throw $this->createNotFoundException();
    }

    $response = new Response($contents);
    $response->headers->set('Content-Type', $this->mimeFromExtension($filename));
    $response->headers->set('Cache-Control', 'private, max-age=300');

    return $response;
}

private function mimeFromExtension(string $filename): string
{
    return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
        'png'           => 'image/png',
        'jpg', 'jpeg'   => 'image/jpeg',
        default         => 'application/octet-stream',
    };
}
```

- [ ] **Step 4: Run the 403 test**

Run:
```bash
vendor/bin/phpunit --filter testNonOwnerCannotFetchEventLogo
```

Expected: PASS.

- [ ] **Step 5: Run all existing event tests to make sure nothing else broke**

Run:
```bash
vendor/bin/phpunit tests/Functional/Admin/EventQrTest.php
```

Expected: all tests pass.

- [ ] **Step 6: Run PHPStan**

Run:
```bash
vendor/bin/phpstan analyse src/Controller/Admin/EventController.php
```

Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add src/Controller/Admin/EventController.php tests/Functional/Admin/EventQrTest.php
git commit -m "feat: add access-controlled logo serving route"
```

---

## Task 13: Failing tests for QR rendering with an event logo

**Files:**
- Modify: `tests/Functional/Admin/EventQrTest.php`

- [ ] **Step 1: Add two new tests — "with logo PNG differs from plain" and "missing storage file degrades gracefully"**

Append to `tests/Functional/Admin/EventQrTest.php` (inside the class):

```php
public function testEventWithLogoProducesDifferentQrPngThanWithoutLogo(): void
{
    $client = self::createClient();
    $container = self::getContainer();

    /** @var EntityManagerInterface $em */
    $em = $container->get(EntityManagerInterface::class);
    /** @var UserPasswordHasherInterface $hasher */
    $hasher = $container->get(UserPasswordHasherInterface::class);

    $alice = new User('alice@example.com', 'Alice');
    $alice->addRole('ROLE_ORGANIZER');
    $alice->setPassword($hasher->hashPassword($alice, 'pw'));

    $event = new Event('summer-fest', 'Summer Fest', new DateTimeImmutable('2026-07-15'), $alice);

    $em->persist($alice);
    $em->persist($event);
    $em->flush();

    $client->loginUser($alice);

    // 1. Plain QR baseline.
    $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/qr.png', (int) $event->getId()));
    $this->assertResponseIsSuccessful();
    $plain = (string) $client->getResponse()->getContent();

    // 2. Write a logo into the in-memory Flysystem and attach it to the event.
    /** @var \League\Flysystem\FilesystemOperator $storage */
    $storage = $container->get('event_logos_storage');
    $storage->write('alice-logo.png', (string) file_get_contents(__DIR__ . '/../../fixtures/logo.png'));
    $event->setLogoFilename('alice-logo.png');
    $em->flush();

    // 3. QR with logo.
    $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/qr.png', (int) $event->getId()));
    $this->assertResponseIsSuccessful();
    $withLogo = (string) $client->getResponse()->getContent();

    $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $withLogo);
    $this->assertNotSame($plain, $withLogo);
}

public function testMissingLogoFileInStorageStillRendersPlainQr(): void
{
    $client = self::createClient();
    $container = self::getContainer();

    /** @var EntityManagerInterface $em */
    $em = $container->get(EntityManagerInterface::class);
    /** @var UserPasswordHasherInterface $hasher */
    $hasher = $container->get(UserPasswordHasherInterface::class);

    $alice = new User('alice@example.com', 'Alice');
    $alice->addRole('ROLE_ORGANIZER');
    $alice->setPassword($hasher->hashPassword($alice, 'pw'));

    $event = new Event('summer-fest', 'Summer Fest', new DateTimeImmutable('2026-07-15'), $alice);
    // Filename set, but no file is written to storage.
    $event->setLogoFilename('does-not-exist.png');

    $em->persist($alice);
    $em->persist($event);
    $em->flush();

    $client->loginUser($alice);
    $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/qr', (int) $event->getId()));

    $this->assertResponseIsSuccessful();
    $this->assertStringContainsString('<svg', (string) $client->getResponse()->getContent());
}
```

- [ ] **Step 2: Run them to confirm they fail**

Run:
```bash
vendor/bin/phpunit --filter 'testEventWithLogoProducesDifferentQrPng|testMissingLogoFileInStorageStillRendersPlainQr'
```

Expected: both fail. The first fails because the controller does not yet pass logo bytes to the renderer (responses identical). The second fails because the controller does not yet handle `FilesystemException`.

---

## Task 14: Wire logo bytes into the QR-rendering controller actions

**Files:**
- Modify: `src/Controller/Admin/EventController.php`

- [ ] **Step 1: Add the `readLogoBytes` helper**

Insert this private method immediately above `private function eventLandingUrl(...)`:

```php
private function readLogoBytes(Event $event): ?string
{
    $filename = $event->getLogoFilename();
    if ($filename === null) {
        return null;
    }
    try {
        return $this->eventLogosStorage->read($filename);
    } catch (FilesystemException $e) {
        $this->logger->warning('Failed to read event logo; rendering QR without it', [
            'event_id' => $event->getId(),
            'filename' => $filename,
            'exception' => $e,
        ]);
        return null;
    }
}
```

- [ ] **Step 2: Use it in `qr()`**

Replace the body of `qr()` with:

```php
$this->denyAccessUnlessGranted(EventVoter::VIEW, $event);

$url = $this->eventLandingUrl($event);

$logoBytes = $this->readLogoBytes($event);
// TODO: when user-level default logos exist, fall back to
// $event->getOwner()->getDefaultLogo() bytes here when $event has no logo of its own.

return $this->render('admin/event/qr.html.twig', [
    'event' => $event,
    'url'   => $url,
    'svg'   => $this->renderer->svg($url, $logoBytes),
]);
```

- [ ] **Step 3: Use it in `qrPng()`**

Replace the body of `qrPng()` with:

```php
$this->denyAccessUnlessGranted(EventVoter::VIEW, $event);

$url = $this->eventLandingUrl($event);

$logoBytes = $this->readLogoBytes($event);
// TODO: when user-level default logos exist, fall back to
// $event->getOwner()->getDefaultLogo() bytes here when $event has no logo of its own.

return new Response(
    $this->renderer->png($url, $logoBytes),
    Response::HTTP_OK,
    [
        'Content-Type'        => 'image/png',
        'Content-Disposition' => sprintf('attachment; filename="event-%s.png"', $event->getSlug()),
    ],
);
```

- [ ] **Step 4: Run the failing tests**

Run:
```bash
vendor/bin/phpunit --filter 'testEventWithLogoProducesDifferentQrPng|testMissingLogoFileInStorageStillRendersPlainQr'
```

Expected: both PASS.

- [ ] **Step 5: Run the full QR test class**

Run:
```bash
vendor/bin/phpunit tests/Functional/Admin/EventQrTest.php
```

Expected: all tests pass.

- [ ] **Step 6: Run PHPStan**

Run:
```bash
vendor/bin/phpstan analyse src/Controller/Admin/EventController.php
```

Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add src/Controller/Admin/EventController.php tests/Functional/Admin/EventQrTest.php
git commit -m "feat: render QR with event logo when present"
```

---

## Task 15: Functional test — upload happy path

**Files:**
- Create: `tests/Functional/Admin/EventLogoUploadTest.php`

- [ ] **Step 1: Create the test file with the happy-path case**

Create `tests/Functional/Admin/EventLogoUploadTest.php`:

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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EventLogoUploadTest extends WebTestCase
{
    public function testOwnerUploadsValidPngLogo(): void
    {
        $client = self::createClient();
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var FilesystemOperator $storage */
        $storage = $container->get('event_logos_storage');

        $alice = new User('alice@example.com', 'Alice');
        $alice->addRole('ROLE_ORGANIZER');
        $alice->setPassword($hasher->hashPassword($alice, 'pw'));

        $event = new Event('summer-fest', 'Summer Fest', new DateTimeImmutable('2026-07-15'), $alice);

        $em->persist($alice);
        $em->persist($event);
        $em->flush();
        $eventId = (int) $event->getId();

        $client->loginUser($alice);
        $crawler = $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', $eventId));
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form();
        $form['event[logoFile][file]']->upload(__DIR__ . '/../../fixtures/logo.png');
        $client->submit($form);

        $this->assertResponseRedirects('/admin/events');

        $em->clear();
        $reloaded = $em->find(Event::class, $eventId);
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getLogoFilename());
        self::assertTrue($storage->fileExists($reloaded->getLogoFilename()));
    }
}
```

- [ ] **Step 2: Run it**

Run:
```bash
vendor/bin/phpunit tests/Functional/Admin/EventLogoUploadTest.php
```

Expected: PASS. If the form field name does not match (`event[logoFile][file]` is the standard Vich nested name), debug by dumping `$crawler->filter('form')->html()` and adjust.

- [ ] **Step 3: Commit**

```bash
git add tests/Functional/Admin/EventLogoUploadTest.php
git commit -m "test: event logo upload happy path"
```

---

## Task 16: Functional test — reject SVG and oversize uploads

**Files:**
- Modify: `tests/Functional/Admin/EventLogoUploadTest.php`

- [ ] **Step 1: Add a helper SVG fixture inline (no file needed)**

Append to `tests/Functional/Admin/EventLogoUploadTest.php`:

```php
public function testSvgUploadIsRejected(): void
{
    $client = self::createClient();
    $container = self::getContainer();

    /** @var EntityManagerInterface $em */
    $em = $container->get(EntityManagerInterface::class);
    /** @var UserPasswordHasherInterface $hasher */
    $hasher = $container->get(UserPasswordHasherInterface::class);

    $alice = new User('alice@example.com', 'Alice');
    $alice->addRole('ROLE_ORGANIZER');
    $alice->setPassword($hasher->hashPassword($alice, 'pw'));

    $event = new Event('summer-fest', 'Summer Fest', new DateTimeImmutable('2026-07-15'), $alice);

    $em->persist($alice);
    $em->persist($event);
    $em->flush();

    // Write an SVG file to a temp path and upload it.
    $svgPath = sys_get_temp_dir() . '/logo-test.svg';
    file_put_contents($svgPath, '<svg xmlns="http://www.w3.org/2000/svg"/>');

    $client->loginUser($alice);
    $crawler = $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', (int) $event->getId()));

    $form = $crawler->selectButton('Save')->form();
    $form['event[logoFile][file]']->upload($svgPath);
    $client->submit($form);

    @unlink($svgPath);

    // Re-rendered form (200), not a redirect.
    $this->assertSame(200, $client->getResponse()->getStatusCode());
    $this->assertStringContainsString(
        'Please upload a PNG or JPEG image.',
        (string) $client->getResponse()->getContent(),
    );

    $em->clear();
    $reloaded = $em->find(Event::class, (int) $event->getId());
    self::assertNotNull($reloaded);
    self::assertNull($reloaded->getLogoFilename());
}

public function testOversizeUploadIsRejected(): void
{
    $client = self::createClient();
    $container = self::getContainer();

    /** @var EntityManagerInterface $em */
    $em = $container->get(EntityManagerInterface::class);
    /** @var UserPasswordHasherInterface $hasher */
    $hasher = $container->get(UserPasswordHasherInterface::class);

    $alice = new User('alice@example.com', 'Alice');
    $alice->addRole('ROLE_ORGANIZER');
    $alice->setPassword($hasher->hashPassword($alice, 'pw'));

    $event = new Event('summer-fest', 'Summer Fest', new DateTimeImmutable('2026-07-15'), $alice);

    $em->persist($alice);
    $em->persist($event);
    $em->flush();

    // Build a >2 MB "PNG" file (real PNG header followed by junk bytes — we only need the validator to see the size).
    $bigPath = sys_get_temp_dir() . '/big.png';
    $header = "\x89PNG\r\n\x1a\n";
    file_put_contents($bigPath, $header . str_repeat('A', 2_100_000));

    $client->loginUser($alice);
    $crawler = $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', (int) $event->getId()));

    $form = $crawler->selectButton('Save')->form();
    $form['event[logoFile][file]']->upload($bigPath);
    $client->submit($form);

    @unlink($bigPath);

    $this->assertSame(200, $client->getResponse()->getStatusCode());
    // Symfony's File constraint emits "The file is too large" by default.
    $this->assertStringContainsString('too large', (string) $client->getResponse()->getContent());

    $em->clear();
    $reloaded = $em->find(Event::class, (int) $event->getId());
    self::assertNotNull($reloaded);
    self::assertNull($reloaded->getLogoFilename());
}
```

- [ ] **Step 2: Run the new tests**

Run:
```bash
vendor/bin/phpunit --filter 'testSvgUploadIsRejected|testOversizeUploadIsRejected'
```

Expected: both PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Functional/Admin/EventLogoUploadTest.php
git commit -m "test: reject SVG and oversize logo uploads"
```

---

## Task 17: Functional test — delete logo via Vich checkbox

**Files:**
- Modify: `tests/Functional/Admin/EventLogoUploadTest.php`

- [ ] **Step 1: Add the delete test**

Append to `tests/Functional/Admin/EventLogoUploadTest.php`:

```php
public function testOwnerDeletesExistingLogoViaCheckbox(): void
{
    $client = self::createClient();
    $container = self::getContainer();

    /** @var EntityManagerInterface $em */
    $em = $container->get(EntityManagerInterface::class);
    /** @var UserPasswordHasherInterface $hasher */
    $hasher = $container->get(UserPasswordHasherInterface::class);
    /** @var FilesystemOperator $storage */
    $storage = $container->get('event_logos_storage');

    $alice = new User('alice@example.com', 'Alice');
    $alice->addRole('ROLE_ORGANIZER');
    $alice->setPassword($hasher->hashPassword($alice, 'pw'));

    $event = new Event('summer-fest', 'Summer Fest', new DateTimeImmutable('2026-07-15'), $alice);

    $em->persist($alice);
    $em->persist($event);
    $em->flush();
    $eventId = (int) $event->getId();

    // Step 1 — upload a logo so there is something to delete.
    $client->loginUser($alice);
    $crawler = $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', $eventId));
    $form = $crawler->selectButton('Save')->form();
    $form['event[logoFile][file]']->upload(__DIR__ . '/../../fixtures/logo.png');
    $client->submit($form);
    $this->assertResponseRedirects('/admin/events');

    $em->clear();
    $reloaded = $em->find(Event::class, $eventId);
    self::assertNotNull($reloaded);
    self::assertNotNull($reloaded->getLogoFilename());
    $storedName = $reloaded->getLogoFilename();

    // Step 2 — re-open the form and check the "delete" box that VichFileType renders.
    $crawler = $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/edit', $eventId));
    $form = $crawler->selectButton('Save')->form();
    $form['event[logoFile][delete]']->tick();
    $client->submit($form);
    $this->assertResponseRedirects('/admin/events');

    $em->clear();
    $afterDelete = $em->find(Event::class, $eventId);
    self::assertNotNull($afterDelete);
    self::assertNull($afterDelete->getLogoFilename());
    self::assertFalse($storage->fileExists($storedName));
}
```

- [ ] **Step 2: Run it**

Run:
```bash
vendor/bin/phpunit --filter testOwnerDeletesExistingLogoViaCheckbox
```

Expected: PASS.

- [ ] **Step 3: Run the entire test suite end-to-end**

Run:
```bash
vendor/bin/phpunit
```

Expected: every test passes.

- [ ] **Step 4: Run PHPStan on the whole project**

Run:
```bash
vendor/bin/phpstan analyse
```

Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add tests/Functional/Admin/EventLogoUploadTest.php
git commit -m "test: delete existing event logo via vich checkbox"
```

---

## Task 18: Manual smoke test in the running app

**Files:** none

- [ ] **Step 1: Start the dev server**

Run:
```bash
symfony server:start -d
```

(Or `php -S 127.0.0.1:8080 -t public` if Symfony CLI is not installed.)

- [ ] **Step 2: Log in and upload a real logo**

Open the admin in a browser, log in, edit an existing event, upload a PNG ~50–200 KB. Save. The event-edit page should reload with the new logo previewed at the top.

- [ ] **Step 3: View the QR**

Open the event's QR page. The QR should render with the uploaded logo centered. Scan it with a phone — it should still resolve to the public landing URL. If scanning fails, the logo is likely too large; reduce `LOGO_WIDTH_RATIO` in `QrCodeRenderer` and retest.

- [ ] **Step 4: Download the PNG**

Click "Download PNG". The downloaded file should be a PNG with the logo embedded.

- [ ] **Step 5: Delete the logo**

Edit the event again, tick the "Delete" checkbox on the logo field, save. The QR page should now show a plain QR without a logo.

- [ ] **Step 6: Stop the server**

Run:
```bash
symfony server:stop
```

(Or `kill` the `php -S` process.)

---

## Done

All 18 tasks complete. The feature is shipped behind no flag — once merged, every event can optionally carry a logo and it renders on both the print page and the PNG download. The user-level fallback hook lives at the top of `EventController::qr()` and `qrPng()`, marked with `// TODO`.
