# Organizer-configurable Preview Quality & Size Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let organizers pick a per-event preview (display) derivative long-edge size and JPEG quality from bounded allowlists, defaulting to today's 1600 px / q85; thumbnails stay fixed.

**Architecture:** A new `PreviewSettings` `#[ORM\Embeddable]` (mirroring `StyleSettings`) carries two validated `int` columns on `Event` under the `preview_` prefix. `DerivativeGenerator::generate()` takes a `PreviewSettings` and reads size/quality from it instead of class constants; `ProcessPhotoHandler` passes `$event->getPreviewSettings()`. A `PreviewSettingsType` form (two `ChoiceType`s built from the allowlist constants) is embedded in `EventType`. Non-nullable columns with DB defaults backfill existing rows to today's behavior.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3, PostgreSQL 16, PHPUnit 13, GrumPHP (phpstan L10, phpcs PSR-12, phpmnd, phpcpd, rector, doctrine:schema:validate).

## Global Constraints

- PHP attributes only — no annotations.
- All work on branch `feature/111-configurable-preview-quality` (GrumPHP blacklists direct `main` commits; branch must match `^(feature|hotfix|bugfix|release)/\d+-`).
- **Claude does not run `git commit`.** Each task ends by running the quality gate; a single commit message is proposed at the end for the user to commit. Any commit the *user* makes must contain `#111`.
- No magic numbers in `src/` (phpmnd) — every numeric literal that isn't a `const` declaration must come from a class constant.
- Never hand-write migrations — generate via `bin/console doctrine:migrations:diff`, edit only `getDescription()`.
- Run PHP / Composer / `bin/console` / `vendor/bin/*` on the **host**.
- Tests fail on any deprecation/notice/warning.
- Allowlists (verbatim): long edge `{1280, 1600, 2048, 2560}` default `1600`; quality `{70, 80, 85, 90}` default `85`. Thumbnail fixed at 400 / q80.

---

## Task 0: Branch setup

**Files:** none (git only)

- [ ] **Step 1: Create the feature branch**

Run:
```bash
git checkout -b feature/111-configurable-preview-quality
```
Expected: `Switched to a new branch 'feature/111-configurable-preview-quality'`

---

## Task 1: `PreviewSettings` embeddable + `Event` wiring + migration

**Files:**
- Create: `src/Entity/PreviewSettings.php`
- Modify: `src/Entity/Event.php` (add embedded property, constructor init, getter)
- Create: `tests/Unit/Entity/PreviewSettingsTest.php`
- Create: `migrations/VersionYYYYMMDDHHMMSS.php` (generated)

**Interfaces:**
- Produces:
  - `App\Entity\PreviewSettings` with `public const array ALLOWED_LONG_EDGES = [1280, 1600, 2048, 2560]`, `public const array ALLOWED_QUALITIES = [70, 80, 85, 90]`, `public const int DEFAULT_LONG_EDGE = 1600`, `public const int DEFAULT_QUALITY = 85`; methods `getLongEdge(): int`, `setLongEdge(int): void`, `getQuality(): int`, `setQuality(int): void`.
  - `App\Entity\Event::getPreviewSettings(): PreviewSettings`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Entity/PreviewSettingsTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\PreviewSettings;
use PHPUnit\Framework\TestCase;

final class PreviewSettingsTest extends TestCase
{
    public function testDefaultsMatchLegacyConstants(): void
    {
        $settings = new PreviewSettings();

        $this->assertSame(1600, $settings->getLongEdge());
        $this->assertSame(85, $settings->getQuality());
    }

    public function testAcceptsAllowlistedValues(): void
    {
        $settings = new PreviewSettings();
        $settings->setLongEdge(2048);
        $settings->setQuality(90);

        $this->assertSame(2048, $settings->getLongEdge());
        $this->assertSame(90, $settings->getQuality());
    }

    public function testDefaultsAreMembersOfTheirAllowlists(): void
    {
        $this->assertContains(PreviewSettings::DEFAULT_LONG_EDGE, PreviewSettings::ALLOWED_LONG_EDGES);
        $this->assertContains(PreviewSettings::DEFAULT_QUALITY, PreviewSettings::ALLOWED_QUALITIES);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Entity/PreviewSettingsTest.php`
Expected: FAIL — `Class "App\Entity\PreviewSettings" not found`.

- [ ] **Step 3: Create the `PreviewSettings` embeddable**

Create `src/Entity/PreviewSettings.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Embeddable]
class PreviewSettings
{
    /** @var list<int> */
    public const array ALLOWED_LONG_EDGES = [1280, 1600, 2048, 2560];

    /** @var list<int> */
    public const array ALLOWED_QUALITIES = [70, 80, 85, 90];

    public const int DEFAULT_LONG_EDGE = 1600;

    public const int DEFAULT_QUALITY = 85;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => self::DEFAULT_LONG_EDGE])]
    #[Assert\Choice(choices: self::ALLOWED_LONG_EDGES, message: 'Choose a supported display image size.')]
    private int $longEdge = self::DEFAULT_LONG_EDGE;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => self::DEFAULT_QUALITY])]
    #[Assert\Choice(choices: self::ALLOWED_QUALITIES, message: 'Choose a supported display image quality.')]
    private int $quality = self::DEFAULT_QUALITY;

    public function getLongEdge(): int
    {
        return $this->longEdge;
    }

    public function setLongEdge(int $longEdge): void
    {
        $this->longEdge = $longEdge;
    }

    public function getQuality(): int
    {
        return $this->quality;
    }

    public function setQuality(int $quality): void
    {
        $this->quality = $quality;
    }
}
```

- [ ] **Step 4: Embed it on `Event`**

In `src/Entity/Event.php`, add the embedded property next to the `$style` embeddable (after line 38):
```php
    #[ORM\Embedded(class: PreviewSettings::class, columnPrefix: 'preview_')]
    private PreviewSettings $preview;
```
In the constructor, alongside `$this->style = new StyleSettings();` (line 99), add:
```php
        $this->preview = new PreviewSettings();
```
Add the getter next to `getStyle()` (after line 110):
```php
    public function getPreviewSettings(): PreviewSettings
    {
        return $this->preview;
    }
```
(`PreviewSettings` is in the same `App\Entity` namespace, so no `use` is required.)

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Entity/PreviewSettingsTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Generate the migration**

Run:
```bash
bin/console doctrine:database:create --env=test --if-not-exists
bin/console doctrine:migrations:diff
```
Expected: a new `migrations/VersionYYYYMMDDHHMMSS.php` adding `preview_long_edge` and `preview_quality` INTEGER NOT NULL columns with `DEFAULT 1600` / `DEFAULT 85` to `events`. Open it and confirm it contains only those two `ADD` columns (edit `getDescription()` to `"#111 add per-event preview size/quality settings"`; do not hand-edit the SQL).

- [ ] **Step 7: Apply the migration and validate the schema**

Run:
```bash
bin/console doctrine:migrations:migrate --no-interaction
bin/console doctrine:migrations:migrate --no-interaction --env=test
bin/console doctrine:schema:validate
```
Expected: migration runs clean; `doctrine:schema:validate` reports mapping **and** database in sync.

- [ ] **Step 8: Run the quality gate for this task**

Run:
```bash
vendor/bin/phpunit tests/Unit/Entity/PreviewSettingsTest.php
vendor/bin/phpstan analyse src/Entity/PreviewSettings.php src/Entity/Event.php
```
Expected: green. (Full `grumphp run` is exercised in the final task.)

---

## Task 2: `DerivativeGenerator` reads `PreviewSettings`

**Files:**
- Modify: `src/Service/Photo/DerivativeGenerator.php`
- Modify: `src/MessageHandler/ProcessPhotoHandler.php:66`
- Modify: `tests/Unit/Service/Photo/DerivativeGeneratorTest.php`

**Interfaces:**
- Consumes: `App\Entity\PreviewSettings` (Task 1).
- Produces: `DerivativeGenerator::generate(string $path, PreviewSettings $preview): array{0:int,1:int,2:int}` — behavior unchanged except the preview long-edge/quality now come from `$preview`.

- [ ] **Step 1: Update the failing test**

Replace the body of `tests/Unit/Service/Photo/DerivativeGeneratorTest.php` with:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Photo;

use App\Entity\PreviewSettings;
use App\Service\Image\GdImageResizer;
use App\Service\Photo\DerivativeGenerator;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class DerivativeGeneratorTest extends TestCase
{
    public function testGeneratesThumbAndDefaultPreviewAndReportsDimensions(): void
    {
        [$generator, $thumbsFs, $previewsFs] = $this->makeGenerator();

        [$width, $height, $derivativeBytes] = $generator->generate('event-1/42.jpg', new PreviewSettings());

        $this->assertSame(3000, $width);
        $this->assertSame(2000, $height);
        $this->assertTrue($thumbsFs->fileExists('event-1/42.jpg'));
        $this->assertTrue($previewsFs->fileExists('event-1/42.jpg'));
        $this->assertSame(
            $thumbsFs->fileSize('event-1/42.jpg') + $previewsFs->fileSize('event-1/42.jpg'),
            $derivativeBytes,
            'Returned derivativeBytes should equal the actual on-disk sum of thumb + preview.',
        );

        $thumbDims = getimagesizefromstring($thumbsFs->read('event-1/42.jpg'));
        $this->assertNotFalse($thumbDims);
        $this->assertSame(400, max($thumbDims[0], $thumbDims[1]));

        $previewDims = getimagesizefromstring($previewsFs->read('event-1/42.jpg'));
        $this->assertNotFalse($previewDims);
        $this->assertSame(1600, max($previewDims[0], $previewDims[1]));
    }

    public function testPreviewHonoursConfiguredLongEdge(): void
    {
        [$generator, , $previewsFs] = $this->makeGenerator();

        $settings = new PreviewSettings();
        $settings->setLongEdge(2048);

        $generator->generate('event-1/42.jpg', $settings);

        $previewDims = getimagesizefromstring($previewsFs->read('event-1/42.jpg'));
        $this->assertNotFalse($previewDims);
        $this->assertSame(2048, max($previewDims[0], $previewDims[1]));
    }

    /**
     * @return array{0:DerivativeGenerator,1:Filesystem,2:Filesystem}
     */
    private function makeGenerator(): array
    {
        $originalsFs = new Filesystem(new InMemoryFilesystemAdapter());
        $thumbsFs    = new Filesystem(new InMemoryFilesystemAdapter());
        $previewsFs  = new Filesystem(new InMemoryFilesystemAdapter());

        $originalBytes = (string) file_get_contents(
            dirname(__DIR__, 3) . '/fixtures/photos/bigger.jpg',
        );
        $originalsFs->write('event-1/42.jpg', $originalBytes);

        $generator = new DerivativeGenerator($originalsFs, $thumbsFs, $previewsFs, new GdImageResizer());

        return [$generator, $thumbsFs, $previewsFs];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/Photo/DerivativeGeneratorTest.php`
Expected: FAIL — `generate()` currently takes one argument / `PreviewSettings` unused, and the 2048 case fails because generation still uses the 1600 constant.

- [ ] **Step 3: Update `DerivativeGenerator`**

In `src/Service/Photo/DerivativeGenerator.php`: remove the two `PREVIEW_*` constants (lines 17–19), keep `THUMB_LONG_EDGE` / `THUMB_QUALITY`, add the `PreviewSettings` import, and change `generate()`:
```php
use App\Entity\PreviewSettings;
```
```php
    public function generate(string $path, PreviewSettings $preview): array
    {
        $image = $this->resizer->decode($this->originals->read($path));

        $width  = imagesx($image);
        $height = imagesy($image);

        $thumbBytes   = $this->resizer->encode(
            $this->resizer->scaleTo($image, $width, $height, self::THUMB_LONG_EDGE),
            self::THUMB_QUALITY,
        );
        $previewBytes = $this->resizer->encode(
            $this->resizer->scaleTo($image, $width, $height, $preview->getLongEdge()),
            $preview->getQuality(),
        );

        $this->thumbs->write($path, $thumbBytes);
        $this->previews->write($path, $previewBytes);

        return [$width, $height, strlen($thumbBytes) + strlen($previewBytes)];
    }
```

- [ ] **Step 4: Update the sole caller**

In `src/MessageHandler/ProcessPhotoHandler.php`, line 66, pass the event's settings:
```php
            [$width, $height, $derivativeBytes] = $this->derivatives->generate($path, $event->getPreviewSettings());
```
(`$event` is already in scope from line 50.)

- [ ] **Step 5: Run tests to verify they pass**

Run:
```bash
vendor/bin/phpunit tests/Unit/Service/Photo/DerivativeGeneratorTest.php
vendor/bin/phpunit tests/Integration/MessageHandler/ProcessPhotoHandlerTest.php
```
Expected: PASS. (The handler test pulls the real handler from the container, so the new argument is wired automatically — the default `PreviewSettings` keeps the existing 1600-edge assertions valid.)

- [ ] **Step 6: Static analysis for the touched files**

Run: `vendor/bin/phpstan analyse src/Service/Photo/DerivativeGenerator.php src/MessageHandler/ProcessPhotoHandler.php`
Expected: no errors.

---

## Task 3: `PreviewSettingsType` form + `EventType` + template + functional test

**Files:**
- Create: `src/Form/PreviewSettingsType.php`
- Modify: `src/Form/EventType.php` (add `preview` child)
- Modify: `templates/admin/event/form.html.twig` (render the new fields)
- Create: `tests/Functional/Admin/EventPreviewSettingsFormTest.php`

**Interfaces:**
- Consumes: `App\Entity\PreviewSettings` (Task 1), rendered field ids `#event_preview_longEdge`, `#event_preview_quality`; POST keys `event[preview][longEdge]`, `event[preview][quality]`.

- [ ] **Step 1: Write the failing functional test**

Create `tests/Functional/Admin/EventPreviewSettingsFormTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\PreviewSettings;
use App\Entity\User;
use App\Repository\EventRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EventPreviewSettingsFormTest extends WebTestCase
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

        $this->owner = new User('preview-owner@example.test', 'Owner');
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
            'Preview',
            new DateTimeImmutable('2026-06-10 10:00'),
            new DateTimeImmutable('2026-06-10 14:00'),
            $this->owner,
        );
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    public function testNewEventDefaultsToLegacyValues(): void
    {
        $event = $this->makeEvent('preview-defaults');

        $this->assertSame(PreviewSettings::DEFAULT_LONG_EDGE, $event->getPreviewSettings()->getLongEdge());
        $this->assertSame(PreviewSettings::DEFAULT_QUALITY, $event->getPreviewSettings()->getQuality());
    }

    public function testAllowlistedValuePersists(): void
    {
        $event = $this->makeEvent('preview-valid');

        $crawler = $this->client->request(
            Request::METHOD_GET,
            sprintf('/admin/events/%d/edit', (int) $event->getId()),
        );
        self::assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('#event_preview_longEdge'), 'preview size select must render.');

        $form = $crawler->selectButton('Save')->form();
        $form['event[preview][longEdge]']->select('2048');
        $form['event[preview][quality]']->select('90');
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/events');

        /** @var EventRepository $events */
        $events   = self::getContainer()->get(EventRepository::class);
        $reloaded = $events->find((int) $event->getId());
        $this->assertInstanceOf(Event::class, $reloaded);
        $this->assertSame(2048, $reloaded->getPreviewSettings()->getLongEdge());
        $this->assertSame(90, $reloaded->getPreviewSettings()->getQuality());
    }

    public function testOutOfAllowlistValueIsRejected(): void
    {
        $event = $this->makeEvent('preview-tamper');

        $crawler = $this->client->request(
            Request::METHOD_GET,
            sprintf('/admin/events/%d/edit', (int) $event->getId()),
        );
        $form   = $crawler->selectButton('Save')->form();
        $values = $form->getPhpValues();
        $this->assertIsArray($values['event']);
        $this->assertIsArray($values['event']['preview']);
        $values['event']['preview']['longEdge'] = '9999';
        $this->client->request(Request::METHOD_POST, $form->getUri(), $values);

        // Invalid submit re-renders the form (200) instead of redirecting, and nothing persists.
        self::assertResponseStatusCodeSame(200);

        /** @var EventRepository $events */
        $events   = self::getContainer()->get(EventRepository::class);
        $reloaded = $events->find((int) $event->getId());
        $this->assertInstanceOf(Event::class, $reloaded);
        $this->assertSame(
            PreviewSettings::DEFAULT_LONG_EDGE,
            $reloaded->getPreviewSettings()->getLongEdge(),
            'An out-of-allowlist value must be rejected — the stored value stays at the default.',
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Admin/EventPreviewSettingsFormTest.php`
Expected: FAIL — `#event_preview_longEdge` does not render yet (form child missing).

- [ ] **Step 3: Create `PreviewSettingsType`**

Create `src/Form/PreviewSettingsType.php`:
```php
<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\PreviewSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<PreviewSettings>
 */
final class PreviewSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('longEdge', ChoiceType::class, [
                'label'   => 'Display image size',
                'choices' => $this->labelledChoices(PreviewSettings::ALLOWED_LONG_EDGES, ' px'),
                'help'    => 'Long edge of the shared display image. Larger looks sharper but costs more storage and bandwidth.',
            ])
            ->add('quality', ChoiceType::class, [
                'label'   => 'Display image quality',
                'choices' => $this->labelledChoices(PreviewSettings::ALLOWED_QUALITIES, ''),
                'help'    => 'JPEG quality of the shared display image.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PreviewSettings::class]);
    }

    /**
     * @param list<int> $values
     *
     * @return array<string, int>
     */
    private function labelledChoices(array $values, string $suffix): array
    {
        $choices = [];
        foreach ($values as $value) {
            $choices[$value . $suffix] = $value;
        }

        return $choices;
    }
}
```

- [ ] **Step 4: Add the child to `EventType`**

In `src/Form/EventType.php`, add the import:
```php
use App\Form\PreviewSettingsType;
```
and add the child immediately after the `style` child (after line 163):
```php
        $builder->add('preview', PreviewSettingsType::class, [
            'label' => false,
        ]);
```

- [ ] **Step 5: Render the fields in the template**

In `templates/admin/event/form.html.twig`, add a preview block inside the right-hand column `<div>` (after the style include on line 48, still inside that `<div>` closing on line 49):
```twig
                <div class="mt-4 space-y-2">
                    <h2 class="text-sm font-semibold">Display image</h2>
                    {{ form_row(form.preview.longEdge) }}
                    {{ form_row(form.preview.quality) }}
                </div>
```

- [ ] **Step 6: Run the functional test to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Admin/EventPreviewSettingsFormTest.php`
Expected: PASS (3 tests).

---

## Task 4: Full quality gate + propose commit

**Files:** none (verification only)

- [ ] **Step 1: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: green — no failures, deprecations, notices, or warnings.

- [ ] **Step 2: Run the full GrumPHP gate**

Run: `vendor/bin/grumphp run`
Expected: all tasks pass (phpstan L10, phpcs PSR-12, phpmnd, phpcpd, rector, securitychecker, `doctrine:schema:validate`).

- [ ] **Step 3: Confirm no stray magic numbers / rector diffs**

Run: `vendor/bin/rector process --dry-run`
Expected: no changes proposed for the new/modified files.

- [ ] **Step 4: Stage changes and propose the commit message**

Run:
```bash
git add src/Entity/PreviewSettings.php src/Entity/Event.php src/Service/Photo/DerivativeGenerator.php \
        src/MessageHandler/ProcessPhotoHandler.php src/Form/PreviewSettingsType.php src/Form/EventType.php \
        templates/admin/event/form.html.twig migrations/ tests/ docs/superpowers/
```
Then **propose** (do not run) this single-line commit message to the user:

```
111 - organizer-configurable preview size/quality (bounded allowlist, defaults 1600/q85) closes #111
```

---

## Self-Review

**Spec coverage:**
- Bounded preview size + quality on `Event`, defaulting 1600/q85 → Task 1 (entity + migration defaults). ✅
- Values clamped/validated, out-of-range rejected → Task 1 (`Assert\Choice`), Task 3 (functional rejection test). ✅
- `DerivativeGenerator` uses event settings → Task 2. ✅
- Newly ingested photos honour config → Task 2 (`ProcessPhotoHandler` passes `getPreviewSettings()`), covered by handler integration test. ✅
- Existing events unchanged → Task 1 (non-nullable columns with DB defaults backfill existing rows; default-case unit + functional tests). ✅
- Thumbnail fixed → Task 2 (THUMB constants retained, thumb assertion stays at 400). ✅
- Migration via diff → Task 1 Step 6. ✅
- No per-plan cap → intentionally omitted (spec: YAGNI).
- Re-ingest of existing previews → intentionally out of scope (spec pairs it with #112).

**Placeholder scan:** No TBD/TODO; every code step shows complete code; every command shows expected output.

**Type consistency:** `PreviewSettings` constant names (`ALLOWED_LONG_EDGES`, `ALLOWED_QUALITIES`, `DEFAULT_LONG_EDGE`, `DEFAULT_QUALITY`) and methods (`getLongEdge`/`setLongEdge`/`getQuality`/`setQuality`) are used identically across Tasks 1–3. `Event::getPreviewSettings()` used consistently in Tasks 2–3. `generate(string $path, PreviewSettings $preview)` signature matches its caller and tests.
