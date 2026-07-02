# Event Styling — Phase 1 (Colors) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let organizers set font/background/button colors + a background-glow toggle per Event, per EventCollection, and per organizer profile, resolved most-specific-wins, and render them on the public event landing + gallery with a live admin preview.

**Architecture:** A shared Doctrine embeddable `StyleSettings` (four nullable fields) is embedded in `Event`, `EventCollection`, and a new `OrganizerProfile` (1:1 with `User`). A `StyleResolver` walks Event → Collection → OrganizerProfile per field (first non-null wins) into an immutable `ResolvedStyle`. Public templates emit CSS-custom-property overrides on a wrapper in `public/_base.html.twig`; unset fields emit **nothing** so silk's theme values stand (visually unchanged). A reusable `StyleSettingsType` form with per-field "override" checkboxes writes `null` for inherited fields; a `style-preview` Stimulus controller mirrors the resolution client-side.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3 (embeddables), DaisyUI v5 (`--color-*` custom properties, silk theme), Stimulus, PHPUnit 13.

## Global Constraints

- PHP attributes only — no annotations.
- PHPStan level 10 clean; `phpcs` PSR-12; `phpmnd` — **no magic numbers in `src/`** (luminance threshold, glow alpha, hex length must be named constants); `rector`; `phpcpd`.
- **Never hand-write migrations** — generate via `bin/console doctrine:migrations:diff`, edit only `getDescription()`. `doctrine:schema:validate` must stay green.
- Branch: `feature/54-event-styling-colors` (already created). Commit messages must contain issue number `54`. Do **not** run `git commit` — the user commits; stage only. (Steps below say "Commit" for the executor's benefit; in this repo, stage + propose message, per user preference.)
- Colors stored as validated 7-char hex strings (`/^#[0-9a-fA-F]{6}$/`); background gradient is **system-generated** from validated hex — no free-form CSS ever accepted.
- Run PHP/Composer/`bin/console`/`vendor/bin/*` on the host.
- System defaults are **null = inherit the silk theme** (do not emit an override). `DEFAULT_*` hexes exist only as structural fallbacks where a concrete color is required (glow fade target).

---

## File Structure

**Create:**
- `src/Entity/StyleSettings.php` — embeddable (4 nullable fields + helpers)
- `src/Entity/OrganizerProfile.php` — 1:1 with User, embeds StyleSettings
- `src/Repository/OrganizerProfileRepository.php`
- `src/Service/Style/ResolvedStyle.php` — immutable resolved value object + derivations
- `src/Service/Style/StyleResolver.php` — resolution service
- `src/Form/StyleSettingsType.php` — reusable style sub-form
- `src/Form/OrganizerProfileType.php` — account styling form
- `templates/_partials/_style_fields.html.twig` — swatch inputs + preview card
- `assets/controllers/style_preview_controller.js`
- `migrations/VersionYYYYMMDDHHMMSS.php` — generated
- Tests: `tests/Unit/Entity/StyleSettingsTest.php`, `tests/Unit/Service/Style/ResolvedStyleTest.php`, `tests/Unit/Service/Style/StyleResolverTest.php`, `tests/Integration/Form/StyleSettingsTypeTest.php`, `tests/Functional/Public/EventStylingRenderTest.php`, `tests/Functional/Admin/EventStyleEditTest.php`, `tests/Functional/Account/OrganizerProfileStyleTest.php`

**Modify:**
- `src/Entity/Event.php` — embed StyleSettings
- `src/Entity/EventCollection.php` — embed StyleSettings
- `src/Form/EventType.php` — add `style` sub-form + `inherited` option
- `src/Form/EventCollectionType.php` — add `style` sub-form + `inherited` option
- `src/Controller/Admin/EventController.php` — inject StyleResolver, pass `inherited`
- `src/Controller/Admin/EventCollectionController.php` — inject StyleResolver, pass `inherited`
- `src/Controller/Public/EventController.php` — inject StyleResolver, pass `resolvedStyle`
- `src/Controller/Account/AccountController.php` — organizer profile style form + POST action
- `templates/public/_base.html.twig` — CSS-var wrapper
- `templates/admin/event/form.html.twig` — render style panel
- `templates/admin/collection/form.html.twig` — render style panel
- `templates/account/show.html.twig` — organizer styling section

---

## Task 1: `StyleSettings` embeddable

**Files:**
- Create: `src/Entity/StyleSettings.php`
- Test: `tests/Unit/Entity/StyleSettingsTest.php`

**Interfaces:**
- Produces: `StyleSettings` with `getFontColor(): ?string` / `setFontColor(?string)`, same for `BackgroundColor`, `ButtonColor`; `getGlowEnabled(): ?bool` / `setGlowEnabled(?bool)`; `isEmpty(): bool`. Regex `HEX_PATTERN = '/^#[0-9a-fA-F]{6}$/'` (public const).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\StyleSettings;
use PHPUnit\Framework\TestCase;

final class StyleSettingsTest extends TestCase
{
    public function testDefaultsToAllNullAndIsEmpty(): void
    {
        $style = new StyleSettings();

        self::assertNull($style->getFontColor());
        self::assertNull($style->getBackgroundColor());
        self::assertNull($style->getButtonColor());
        self::assertNull($style->getGlowEnabled());
        self::assertTrue($style->isEmpty());
    }

    public function testSettersRoundTripAndIsNotEmpty(): void
    {
        $style = new StyleSettings();
        $style->setFontColor('#1F2937');
        $style->setButtonColor('#FF6B35');
        $style->setGlowEnabled(true);

        self::assertSame('#1F2937', $style->getFontColor());
        self::assertSame('#FF6B35', $style->getButtonColor());
        self::assertTrue($style->getGlowEnabled());
        self::assertFalse($style->isEmpty());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Entity/StyleSettingsTest.php`
Expected: FAIL — `Class "App\Entity\StyleSettings" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Embeddable]
class StyleSettings
{
    public const string HEX_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    #[ORM\Column(type: Types::STRING, length: 7, nullable: true)]
    #[Assert\Regex(pattern: self::HEX_PATTERN, message: 'Use a #RRGGBB hex color.')]
    private ?string $fontColor = null;

    #[ORM\Column(type: Types::STRING, length: 7, nullable: true)]
    #[Assert\Regex(pattern: self::HEX_PATTERN, message: 'Use a #RRGGBB hex color.')]
    private ?string $backgroundColor = null;

    #[ORM\Column(type: Types::STRING, length: 7, nullable: true)]
    #[Assert\Regex(pattern: self::HEX_PATTERN, message: 'Use a #RRGGBB hex color.')]
    private ?string $buttonColor = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $glowEnabled = null;

    public function getFontColor(): ?string
    {
        return $this->fontColor;
    }

    public function setFontColor(?string $fontColor): void
    {
        $this->fontColor = $fontColor;
    }

    public function getBackgroundColor(): ?string
    {
        return $this->backgroundColor;
    }

    public function setBackgroundColor(?string $backgroundColor): void
    {
        $this->backgroundColor = $backgroundColor;
    }

    public function getButtonColor(): ?string
    {
        return $this->buttonColor;
    }

    public function setButtonColor(?string $buttonColor): void
    {
        $this->buttonColor = $buttonColor;
    }

    public function getGlowEnabled(): ?bool
    {
        return $this->glowEnabled;
    }

    public function setGlowEnabled(?bool $glowEnabled): void
    {
        $this->glowEnabled = $glowEnabled;
    }

    public function isEmpty(): bool
    {
        return $this->fontColor === null
            && $this->backgroundColor === null
            && $this->buttonColor === null
            && $this->glowEnabled === null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Entity/StyleSettingsTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Stage + propose commit**

```bash
git add src/Entity/StyleSettings.php tests/Unit/Entity/StyleSettingsTest.php
```
Proposed message: `54 - add StyleSettings embeddable for organizer color styling`

---

## Task 2: `ResolvedStyle` value object

**Files:**
- Create: `src/Service/Style/ResolvedStyle.php`
- Test: `tests/Unit/Service/Style/ResolvedStyleTest.php`

**Interfaces:**
- Consumes: nothing (pure).
- Produces: `new ResolvedStyle(?string $fontColor, ?string $backgroundColor, ?string $buttonColor, bool $glowEnabled)` with readonly public props; `buttonContentColor(): ?string`; `backgroundCss(): ?string`. Constants `DEFAULT_GLOW_BASE = '#FFFFFF'`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Style;

use App\Service\Style\ResolvedStyle;
use PHPUnit\Framework\TestCase;

final class ResolvedStyleTest extends TestCase
{
    public function testNullFieldsStayNull(): void
    {
        $style = new ResolvedStyle(null, null, null, false);

        self::assertNull($style->fontColor);
        self::assertNull($style->backgroundColor);
        self::assertNull($style->buttonContentColor());
        self::assertNull($style->backgroundCss());
    }

    public function testButtonContentColorIsWhiteOnDarkButton(): void
    {
        $style = new ResolvedStyle(null, null, '#1F2937', false);
        self::assertSame('#FFFFFF', $style->buttonContentColor());
    }

    public function testButtonContentColorIsBlackOnLightButton(): void
    {
        $style = new ResolvedStyle(null, null, '#FDE68A', false);
        self::assertSame('#000000', $style->buttonContentColor());
    }

    public function testBackgroundCssIsFlatWhenGlowOff(): void
    {
        $style = new ResolvedStyle(null, '#EEEEEE', '#FF6B35', false);
        self::assertSame('#EEEEEE', $style->backgroundCss());
    }

    public function testBackgroundCssIsGradientDerivedFromButtonWhenGlowOn(): void
    {
        $style = new ResolvedStyle(null, '#FFFFFF', '#FF6B35', true);
        // #FF6B35 -> rgb(255, 107, 53)
        self::assertSame(
            'radial-gradient(circle, rgba(255, 107, 53, 0.4), #FFFFFF)',
            $style->backgroundCss(),
        );
    }

    public function testGlowOnWithoutButtonFallsBackToFlatBackground(): void
    {
        $style = new ResolvedStyle(null, '#FFFFFF', null, true);
        self::assertSame('#FFFFFF', $style->backgroundCss());
    }

    public function testGlowOnWithoutBackgroundUsesWhiteBase(): void
    {
        $style = new ResolvedStyle(null, null, '#FF6B35', true);
        self::assertSame(
            'radial-gradient(circle, rgba(255, 107, 53, 0.4), #FFFFFF)',
            $style->backgroundCss(),
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/Style/ResolvedStyleTest.php`
Expected: FAIL — `Class "App\Service\Style\ResolvedStyle" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Service\Style;

final class ResolvedStyle
{
    public const string DEFAULT_GLOW_BASE = '#FFFFFF';

    private const float LUMINANCE_THRESHOLD = 0.5;

    private const float GLOW_ALPHA = 0.4;

    private const int HEX_BASE = 16;

    private const float SRGB_DIVISOR = 255.0;

    private const float LUMA_R = 0.2126;

    private const float LUMA_G = 0.7152;

    private const float LUMA_B = 0.0722;

    public function __construct(
        public readonly ?string $fontColor,
        public readonly ?string $backgroundColor,
        public readonly ?string $buttonColor,
        public readonly bool $glowEnabled,
    ) {
    }

    public function buttonContentColor(): ?string
    {
        if ($this->buttonColor === null) {
            return null;
        }

        return self::relativeLuminance($this->buttonColor) > self::LUMINANCE_THRESHOLD
            ? '#000000'
            : '#FFFFFF';
    }

    public function backgroundCss(): ?string
    {
        if ($this->glowEnabled && $this->buttonColor !== null) {
            [$r, $g, $b] = self::hexToRgb($this->buttonColor);
            $base        = $this->backgroundColor ?? self::DEFAULT_GLOW_BASE;

            return sprintf(
                'radial-gradient(circle, rgba(%d, %d, %d, %s), %s)',
                $r,
                $g,
                $b,
                self::GLOW_ALPHA,
                $base,
            );
        }

        return $this->backgroundColor;
    }

    /** @return array{int, int, int} */
    private static function hexToRgb(string $hex): array
    {
        return [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];
    }

    private static function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = self::hexToRgb($hex);

        return (
            self::LUMA_R * ($r / self::SRGB_DIVISOR)
            + self::LUMA_G * ($g / self::SRGB_DIVISOR)
            + self::LUMA_B * ($b / self::SRGB_DIVISOR)
        );
    }
}
```

*(Note: `HEX_BASE` is referenced conceptually via `hexdec`; if PHPStan/phpmnd flags it as unused, drop the constant. `hexdec` needs no base arg.)* — remove `HEX_BASE` before committing since `hexdec` is used, to avoid an unused-constant lint.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Service/Style/ResolvedStyleTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Stage + propose commit**

```bash
git add src/Service/Style/ResolvedStyle.php tests/Unit/Service/Style/ResolvedStyleTest.php
```
Proposed message: `54 - add ResolvedStyle value object with glow + contrast derivation`

---

## Task 3: `OrganizerProfile` entity + repository, embed into Event & EventCollection, migration

**Files:**
- Create: `src/Entity/OrganizerProfile.php`, `src/Repository/OrganizerProfileRepository.php`
- Modify: `src/Entity/Event.php`, `src/Entity/EventCollection.php`
- Create: `migrations/Version*.php` (generated)
- Test: `tests/Integration/Migrations/` is covered by CI schema-validate; add `tests/Unit/Entity/EventStyleAccessorTest.php`

**Interfaces:**
- Produces: `Event::getStyle(): StyleSettings`, `EventCollection::getStyle(): StyleSettings`, `OrganizerProfile` with `__construct(User $user)`, `getUser(): User`, `getStyle(): StyleSettings`; `OrganizerProfileRepository::findOneBy(['user' => $user])`.
- Consumes: `StyleSettings` (Task 1).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Event;
use App\Entity\EventCollection;
use App\Entity\StyleSettings;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class EventStyleAccessorTest extends TestCase
{
    public function testEventExposesNonNullStyleSettings(): void
    {
        $event = new Event(
            'my-slug',
            'My Event',
            new DateTimeImmutable('2026-07-01 12:00'),
            new DateTimeImmutable('2026-07-01 14:00'),
            new User(),
        );

        self::assertInstanceOf(StyleSettings::class, $event->getStyle());
        self::assertTrue($event->getStyle()->isEmpty());
    }

    public function testCollectionExposesNonNullStyleSettings(): void
    {
        $collection = new EventCollection('c-slug', 'Coll', new User());

        self::assertInstanceOf(StyleSettings::class, $collection->getStyle());
    }
}
```

*(Check the `User` constructor signature — if `User` requires args, adjust the fixtures accordingly. Read `src/Entity/User.php` first.)*

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Entity/EventStyleAccessorTest.php`
Expected: FAIL — `Call to undefined method App\Entity\Event::getStyle()`.

- [ ] **Step 3: Embed StyleSettings into Event**

In `src/Entity/Event.php`, add the import and an embedded property + accessor. Initialize it so it is never null:

```php
use App\Entity\StyleSettings;
```

Add property (near the other columns, before the constructor):

```php
    #[ORM\Embedded(class: StyleSettings::class, columnPrefix: 'style_')]
    private StyleSettings $style;
```

Initialize in the constructor body (after the existing UTC pinning):

```php
        $this->style = new StyleSettings();
```

Add accessor:

```php
    public function getStyle(): StyleSettings
    {
        return $this->style;
    }
```

- [ ] **Step 4: Embed StyleSettings into EventCollection**

In `src/Entity/EventCollection.php`:

```php
use App\Entity\StyleSettings;
```

```php
    #[ORM\Embedded(class: StyleSettings::class, columnPrefix: 'style_')]
    private StyleSettings $style;
```

Initialize in the constructor body (where `$this->events = new ArrayCollection();` is):

```php
        $this->style = new StyleSettings();
```

Accessor:

```php
    public function getStyle(): StyleSettings
    {
        return $this->style;
    }
```

- [ ] **Step 5: Create OrganizerProfile entity**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrganizerProfileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrganizerProfileRepository::class)]
#[ORM\Table(name: 'organizer_profiles')]
#[ORM\UniqueConstraint(name: 'uniq_organizer_profiles_user', columns: ['user_id'])]
class OrganizerProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Embedded(class: StyleSettings::class, columnPrefix: 'style_')]
    private StyleSettings $style;

    public function __construct(
        #[ORM\OneToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false)]
        private readonly User $user,
    ) {
        $this->style = new StyleSettings();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getStyle(): StyleSettings
    {
        return $this->style;
    }
}
```

- [ ] **Step 6: Create OrganizerProfileRepository**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OrganizerProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganizerProfile>
 */
final class OrganizerProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizerProfile::class);
    }
}
```

- [ ] **Step 7: Run the accessor test**

Run: `vendor/bin/phpunit tests/Unit/Entity/EventStyleAccessorTest.php`
Expected: PASS.

- [ ] **Step 8: Generate the migration**

```bash
bin/console doctrine:migrations:diff
```
Expected: a new `migrations/Version*.php` adding `style_*` columns to `events` and `event_collections` and creating `organizer_profiles`. Edit only `getDescription()` to read e.g. `Add organizer color styling (StyleSettings) to events, collections, and organizer_profiles (#54)`. Do **not** hand-edit the SQL.

- [ ] **Step 9: Migrate + validate schema (test DB)**

```bash
bin/console doctrine:database:create --env=test --if-not-exists
bin/console doctrine:migrations:migrate --env=test -n
bin/console doctrine:schema:validate --env=test
```
Expected: schema-validate reports mapping **and** database in sync.

- [ ] **Step 10: Stage + propose commit**

```bash
git add src/Entity/Event.php src/Entity/EventCollection.php src/Entity/OrganizerProfile.php \
  src/Repository/OrganizerProfileRepository.php migrations/Version*.php \
  tests/Unit/Entity/EventStyleAccessorTest.php
```
Proposed message: `54 - embed StyleSettings on events/collections, add OrganizerProfile + migration`

---

## Task 4: `StyleResolver` service

**Files:**
- Create: `src/Service/Style/StyleResolver.php`
- Test: `tests/Unit/Service/Style/StyleResolverTest.php`

**Interfaces:**
- Consumes: `StyleSettings` (Task 1), `ResolvedStyle` (Task 2), `OrganizerProfileRepository` (Task 3), `Event`, `EventCollection`, `User`.
- Produces:
  - `resolve(Event $event): ResolvedStyle`
  - `resolveChain(?StyleSettings ...$tiers): ResolvedStyle`
  - `profileStyleFor(User $owner): ?StyleSettings`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Style;

use App\Entity\StyleSettings;
use App\Repository\OrganizerProfileRepository;
use App\Service\Style\StyleResolver;
use PHPUnit\Framework\TestCase;

final class StyleResolverTest extends TestCase
{
    private function resolver(): StyleResolver
    {
        return new StyleResolver($this->createMock(OrganizerProfileRepository::class));
    }

    private function style(?string $font, ?string $bg, ?string $btn, ?bool $glow): StyleSettings
    {
        $s = new StyleSettings();
        $s->setFontColor($font);
        $s->setBackgroundColor($bg);
        $s->setButtonColor($btn);
        $s->setGlowEnabled($glow);

        return $s;
    }

    public function testEmptyChainResolvesToAllNullAndGlowFalse(): void
    {
        $resolved = $this->resolver()->resolveChain(null, null, null);

        self::assertNull($resolved->fontColor);
        self::assertNull($resolved->buttonColor);
        self::assertFalse($resolved->glowEnabled);
    }

    public function testMostSpecificTierWinsPerField(): void
    {
        $event      = $this->style('#111111', null, null, null);
        $collection = $this->style('#222222', '#333333', null, null);
        $organizer  = $this->style('#999999', '#888888', '#777777', true);

        $resolved = $this->resolver()->resolveChain($event, $collection, $organizer);

        self::assertSame('#111111', $resolved->fontColor);       // from event
        self::assertSame('#333333', $resolved->backgroundColor); // event null -> collection
        self::assertSame('#777777', $resolved->buttonColor);     // only organizer set
        self::assertTrue($resolved->glowEnabled);                // only organizer set
    }

    public function testFalseGlowAtSpecificTierWinsOverTrueAtParent(): void
    {
        $event     = $this->style(null, null, null, false);
        $organizer = $this->style(null, null, null, true);

        $resolved = $this->resolver()->resolveChain($event, null, $organizer);

        self::assertFalse($resolved->glowEnabled); // explicit false beats inherited true
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/Style/StyleResolverTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Service\Style;

use App\Entity\Event;
use App\Entity\StyleSettings;
use App\Entity\User;
use App\Repository\OrganizerProfileRepository;

final class StyleResolver
{
    public function __construct(
        private readonly OrganizerProfileRepository $profiles,
    ) {
    }

    public function resolve(Event $event): ResolvedStyle
    {
        return $this->resolveChain(
            $event->getStyle(),
            $event->getCollection()?->getStyle(),
            $this->profileStyleFor($event->getOwner()),
        );
    }

    public function resolveChain(?StyleSettings ...$tiers): ResolvedStyle
    {
        return new ResolvedStyle(
            $this->firstColor($tiers, static fn (StyleSettings $s): ?string => $s->getFontColor()),
            $this->firstColor($tiers, static fn (StyleSettings $s): ?string => $s->getBackgroundColor()),
            $this->firstColor($tiers, static fn (StyleSettings $s): ?string => $s->getButtonColor()),
            $this->firstBool($tiers, static fn (StyleSettings $s): ?bool => $s->getGlowEnabled()) ?? false,
        );
    }

    public function profileStyleFor(User $owner): ?StyleSettings
    {
        return $this->profiles->findOneBy(['user' => $owner])?->getStyle();
    }

    /**
     * @param array<int, StyleSettings|null> $tiers
     * @param callable(StyleSettings): ?string $get
     */
    private function firstColor(array $tiers, callable $get): ?string
    {
        foreach ($tiers as $tier) {
            if ($tier !== null) {
                $value = $get($tier);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, StyleSettings|null> $tiers
     * @param callable(StyleSettings): ?bool $get
     */
    private function firstBool(array $tiers, callable $get): ?bool
    {
        foreach ($tiers as $tier) {
            if ($tier !== null) {
                $value = $get($tier);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Service/Style/StyleResolverTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Stage + propose commit**

```bash
git add src/Service/Style/StyleResolver.php tests/Unit/Service/Style/StyleResolverTest.php
```
Proposed message: `54 - add StyleResolver walking Event->Collection->Organizer per field`

---

## Task 5: Public rendering (CSS-var wrapper)

**Files:**
- Modify: `src/Controller/Public/EventController.php`, `templates/public/_base.html.twig`
- Test: `tests/Functional/Public/EventStylingRenderTest.php`

**Interfaces:**
- Consumes: `StyleResolver::resolve(Event): ResolvedStyle` (Task 4).
- Produces: templates receive a `resolvedStyle` variable (`ResolvedStyle`).

- [ ] **Step 1: Write the failing test**

Create `tests/Functional/Public/EventStylingRenderTest.php`. Model it on an existing functional test under `tests/Functional/Public/` for fixture/setup conventions (read one first — e.g. how events are created and persisted). Core assertions:

```php
public function testStyledEventEmitsCssVariables(): void
{
    $client = static::createClient();
    // ... create + persist an Event owned by a user; set:
    //   $event->getStyle()->setFontColor('#123456');
    //   $event->getStyle()->setButtonColor('#FF6B35');
    //   $event->getStyle()->setGlowEnabled(true);
    // flush.

    $crawler = $client->request('GET', '/e/' . $event->getSlug());

    self::assertResponseIsSuccessful();
    $style = $crawler->filter('[data-style-root]')->attr('style');
    self::assertStringContainsString('--color-base-content: #123456', $style);
    self::assertStringContainsString('--color-primary: #FF6B35', $style);
    self::assertStringContainsString('radial-gradient(circle, rgba(255, 107, 53, 0.4)', $style);
}

public function testUnstyledEventEmitsNoStyleOverrides(): void
{
    // create an event with an all-null style, flush.
    $crawler = $client->request('GET', '/e/' . $event->getSlug());
    self::assertResponseIsSuccessful();
    // wrapper present but carries no --color-* overrides
    $root = $crawler->filter('[data-style-root]');
    self::assertSame(0, $root->filter('[style*="--color-"]')->count());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Public/EventStylingRenderTest.php`
Expected: FAIL — `resolvedStyle` not defined / no `[data-style-root]`.

- [ ] **Step 3: Inject StyleResolver + pass resolvedStyle in the controller**

In `src/Controller/Public/EventController.php` constructor, add:

```php
use App\Service\Style\StyleResolver;
```
```php
        private readonly StyleResolver $styleResolver,
```

In `landing()`, add to the `render(...)` array:

```php
            'resolvedStyle' => $this->styleResolver->resolve($event),
```

In `photos()`, locate the final `$this->render('public/event/photos.html.twig', [...])` call and add the same `'resolvedStyle' => $this->styleResolver->resolve($event),` key. (The 304 early-return path renders nothing, so it needs no change.)

- [ ] **Step 4: Add the wrapper to the base template**

In `templates/public/_base.html.twig`, replace the inner content div with a style-carrying wrapper. Change:

```twig
            <div class="mx-auto max-w-5xl px-4 py-10">
                {% block public_main %}{% endblock %}
            </div>
```

to:

```twig
            <div
                class="mx-auto max-w-5xl px-4 py-10"
                data-style-root
                {% if resolvedStyle is defined and resolvedStyle %}style="
                    {%- if resolvedStyle.fontColor %}--color-base-content: {{ resolvedStyle.fontColor }};{% endif -%}
                    {%- if resolvedStyle.backgroundColor %}--color-base-100: {{ resolvedStyle.backgroundColor }};{% endif -%}
                    {%- if resolvedStyle.buttonColor %}--color-primary: {{ resolvedStyle.buttonColor }}; --color-primary-content: {{ resolvedStyle.buttonContentColor }};{% endif -%}
                    {%- if resolvedStyle.backgroundCss %}background: {{ resolvedStyle.backgroundCss }};{% endif -%}
                "{% endif %}
            >
                {% block public_main %}{% endblock %}
            </div>
```

*(Twig autoescapes the attribute; all interpolated values are validated hex or system-built from validated hex.)*

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Public/EventStylingRenderTest.php`
Expected: PASS.

- [ ] **Step 6: Manual render-verify**

```bash
docker compose up -d
```
Visit `http://localhost:8080/e/<slug>` for a styled event; confirm the card background glow, text color, and button color apply, and that an unstyled event looks identical to before.

- [ ] **Step 7: Stage + propose commit**

```bash
git add src/Controller/Public/EventController.php templates/public/_base.html.twig \
  tests/Functional/Public/EventStylingRenderTest.php
```
Proposed message: `54 - render resolved event styling as CSS variables on public pages`

---

## Task 6: `StyleSettingsType` reusable form

**Files:**
- Create: `src/Form/StyleSettingsType.php`
- Test: `tests/Integration/Form/StyleSettingsTypeTest.php`

**Interfaces:**
- Consumes: `StyleSettings` (Task 1), `ResolvedStyle` (Task 2).
- Produces: form type with `data_class = StyleSettings`, options `inherited` (`?ResolvedStyle`, default null). Child field names: `fontColor`, `backgroundColor`, `buttonColor` (TextType, mapped); `glowEnabled` (CheckboxType, mapped); `customFontColor`, `customBackgroundColor`, `customButtonColor`, `customGlow` (CheckboxType, unmapped). On submit, unchecked custom → the corresponding model field becomes `null`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Form;

use App\Entity\StyleSettings;
use App\Form\StyleSettingsType;
use Symfony\Component\Form\Test\TypeTestCase;

final class StyleSettingsTypeTest extends TypeTestCase
{
    public function testOverrideOffStoresNull(): void
    {
        $model = new StyleSettings();
        $form  = $this->factory->create(StyleSettingsType::class, $model);

        $form->submit([
            'customFontColor'       => false,
            'fontColor'             => '#123456',
            'customBackgroundColor' => false,
            'backgroundColor'       => '#654321',
            'customButtonColor'     => false,
            'buttonColor'           => '#abcdef',
            'customGlow'            => false,
            'glowEnabled'           => true,
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertNull($model->getFontColor());
        self::assertNull($model->getBackgroundColor());
        self::assertNull($model->getButtonColor());
        self::assertNull($model->getGlowEnabled());
    }

    public function testOverrideOnStoresValue(): void
    {
        $model = new StyleSettings();
        $form  = $this->factory->create(StyleSettingsType::class, $model);

        $form->submit([
            'customFontColor'       => true,
            'fontColor'             => '#123456',
            'customBackgroundColor' => false,
            'backgroundColor'       => '#000000',
            'customButtonColor'     => true,
            'buttonColor'           => '#FF6B35',
            'customGlow'            => true,
            'glowEnabled'           => true,
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertSame('#123456', $model->getFontColor());
        self::assertNull($model->getBackgroundColor());
        self::assertSame('#FF6B35', $model->getButtonColor());
        self::assertTrue($model->getGlowEnabled());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Form/StyleSettingsTypeTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\StyleSettings;
use App\Service\Style\ResolvedStyle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<StyleSettings>
 */
final class StyleSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $hex = [new Assert\Regex(pattern: StyleSettings::HEX_PATTERN, message: 'Use a #RRGGBB hex color.')];

        $builder
            ->add('customFontColor', CheckboxType::class, ['mapped' => false, 'required' => false, 'label' => 'Customize font color'])
            ->add('fontColor', TextType::class, ['required' => false, 'constraints' => $hex])
            ->add('customBackgroundColor', CheckboxType::class, ['mapped' => false, 'required' => false, 'label' => 'Customize background color'])
            ->add('backgroundColor', TextType::class, ['required' => false, 'constraints' => $hex])
            ->add('customButtonColor', CheckboxType::class, ['mapped' => false, 'required' => false, 'label' => 'Customize button color'])
            ->add('buttonColor', TextType::class, ['required' => false, 'constraints' => $hex])
            ->add('customGlow', CheckboxType::class, ['mapped' => false, 'required' => false, 'label' => 'Customize background glow'])
            ->add('glowEnabled', CheckboxType::class, ['mapped' => false, 'required' => false, 'label' => 'Enable background glow']);

        $builder->addEventListener(FormEvents::POST_SET_DATA, $this->initOverrideCheckboxes(...));
        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->applyOverrides(...));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => StyleSettings::class,
            'inherited'  => null,
        ]);
        $resolver->setAllowedTypes('inherited', ['null', ResolvedStyle::class]);
    }

    private function initOverrideCheckboxes(FormEvent $event): void
    {
        $style = $event->getData();
        $form  = $event->getForm();
        if (!$style instanceof StyleSettings) {
            return;
        }

        $form->get('customFontColor')->setData($style->getFontColor() !== null);
        $form->get('customBackgroundColor')->setData($style->getBackgroundColor() !== null);
        $form->get('customButtonColor')->setData($style->getButtonColor() !== null);
        $form->get('customGlow')->setData($style->getGlowEnabled() !== null);
        $form->get('glowEnabled')->setData($style->getGlowEnabled() === true);
    }

    private function applyOverrides(FormEvent $event): void
    {
        $style = $event->getData();
        $form  = $event->getForm();
        if (!$style instanceof StyleSettings) {
            return;
        }

        $style->setFontColor($form->get('customFontColor')->getData() === true ? self::asString($form->get('fontColor')->getData()) : null);
        $style->setBackgroundColor($form->get('customBackgroundColor')->getData() === true ? self::asString($form->get('backgroundColor')->getData()) : null);
        $style->setButtonColor($form->get('customButtonColor')->getData() === true ? self::asString($form->get('buttonColor')->getData()) : null);
        $style->setGlowEnabled($form->get('customGlow')->getData() === true ? ($form->get('glowEnabled')->getData() === true) : null);
    }

    private static function asString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
```

*(`glowEnabled` is unmapped so it can represent value-only; `customGlow` drives the null/non-null decision. All four model writes happen in `applyOverrides`, so no mapped child silently pre-writes a value we then can't distinguish.)*

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Form/StyleSettingsTypeTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Stage + propose commit**

```bash
git add src/Form/StyleSettingsType.php tests/Integration/Form/StyleSettingsTypeTest.php
```
Proposed message: `54 - add StyleSettingsType with per-field override checkboxes`

---

## Task 7: Wire style form into Event + EventCollection admin

**Files:**
- Modify: `src/Form/EventType.php`, `src/Form/EventCollectionType.php`, `src/Controller/Admin/EventController.php`, `src/Controller/Admin/EventCollectionController.php`, `templates/admin/event/form.html.twig`, `templates/admin/collection/form.html.twig`
- Create: `templates/_partials/_style_fields.html.twig`
- Test: `tests/Functional/Admin/EventStyleEditTest.php`

**Interfaces:**
- Consumes: `StyleSettingsType` (Task 6), `StyleResolver` (Task 4).
- Produces: `EventType`/`EventCollectionType` gain an `inherited` option (`?ResolvedStyle`, default null) forwarded to the `style` child.

- [ ] **Step 1: Write the failing functional test**

Create `tests/Functional/Admin/EventStyleEditTest.php` (model setup on an existing admin functional test — read one under `tests/Functional/Admin/`). Assert that submitting the event edit form with `event[style][customButtonColor]=1` and `event[style][buttonColor]=#FF6B35` persists `#FF6B35` on `$event->getStyle()->getButtonColor()`, and that leaving `customFontColor` unchecked keeps `getFontColor()` null.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Admin/EventStyleEditTest.php`
Expected: FAIL — no `style` field in the form.

- [ ] **Step 3: Add `style` + `inherited` to EventType**

In `src/Form/EventType.php`:

```php
use App\Form\StyleSettingsType;
use App\Service\Style\ResolvedStyle;
```

In `buildForm`, after the existing fields, add:

```php
        $builder->add('style', StyleSettingsType::class, [
            'label'     => false,
            'inherited' => $options['inherited'],
        ]);
```

In `configureOptions`:

```php
        $resolver->setDefaults(['data_class' => Event::class, 'mail_active' => false, 'inherited' => null]);
        $resolver->setAllowedTypes('mail_active', 'bool');
        $resolver->setAllowedTypes('inherited', ['null', ResolvedStyle::class]);
```

- [ ] **Step 4: Add `style` + `inherited` to EventCollectionType**

In `src/Form/EventCollectionType.php`:

```php
use App\Form\StyleSettingsType;
use App\Service\Style\ResolvedStyle;
```
```php
        $builder->add('style', StyleSettingsType::class, [
            'label'     => false,
            'inherited' => $options['inherited'],
        ]);
```
```php
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => EventCollection::class, 'inherited' => null]);
        $resolver->setAllowedTypes('inherited', ['null', ResolvedStyle::class]);
    }
```

- [ ] **Step 5: Inject StyleResolver + pass `inherited` in EventController**

In `src/Controller/Admin/EventController.php` add `use App\Service\Style\StyleResolver;` and constructor property `private readonly StyleResolver $styleResolver,`.

In `edit()`, build the inherited chain (parent tiers only — exclude the event's own style) and pass it:

```php
        $inherited = $this->styleResolver->resolveChain(
            $event->getCollection()?->getStyle(),
            $this->styleResolver->profileStyleFor($event->getOwner()),
        );
        $form = $this->createForm(EventType::class, $event, [
            'mail_active' => $mailActive,
            'inherited'   => $inherited,
        ]);
```

In `new()`, pass `'inherited' => $this->styleResolver->resolveChain()` (all-null → the form shows theme defaults for inherited display).

- [ ] **Step 6: Inject StyleResolver + pass `inherited` in EventCollectionController**

In `src/Controller/Admin/EventCollectionController.php` add the resolver, then in `edit()`:

```php
        $inherited = $this->styleResolver->resolveChain(
            $this->styleResolver->profileStyleFor($collection->getOwner()),
        );
        $form = $this->createForm(EventCollectionType::class, $collection, ['inherited' => $inherited]);
```
In `new()`, `'inherited' => $this->styleResolver->resolveChain()`.

- [ ] **Step 7: Create the shared style-fields partial**

`templates/_partials/_style_fields.html.twig` — renders the style sub-form as a preview panel wired to the Stimulus controller. `styleForm` is a form view for the `style` child; `inherited` is the `ResolvedStyle` for inherited display.

```twig
{# params: styleForm (FormView for the 'style' child), inherited (ResolvedStyle|null) #}
<div class="space-y-4"
     {{ stimulus_controller('style-preview') }}
     data-style-preview-inherited-font-value="{{ inherited.fontColor ?? '' }}"
     data-style-preview-inherited-background-value="{{ inherited.backgroundColor ?? '' }}"
     data-style-preview-inherited-button-value="{{ inherited.buttonColor ?? '' }}">

    {# Live preview card #}
    <div data-style-preview-target="card"
         class="card shadow-sm overflow-hidden border border-base-300">
        <div class="card-body items-center text-center gap-3">
            <h3 class="text-xl font-bold" data-style-preview-target="heading">Preview</h3>
            <button type="button" class="btn" data-style-preview-target="button">Meld je aan</button>
        </div>
    </div>

    {% for field, label in {
        'fontColor': 'Font color',
        'backgroundColor': 'Background color',
        'buttonColor': 'Button color'
    } %}
        <div class="flex items-center gap-3">
            <label class="w-40 text-sm font-medium">{{ label }}</label>
            {{ form_widget(attribute(styleForm, 'custom' ~ field|capitalize), {
                attr: {'data-style-preview-target': 'toggle', 'data-field': field}
            }) }}
            {{ form_widget(attribute(styleForm, field), {
                type: 'color',
                attr: {'data-style-preview-target': 'input', 'data-field': field}
            }) }}
        </div>
    {% endfor %}

    <div class="flex items-center gap-3">
        <label class="w-40 text-sm font-medium">Background glow</label>
        {{ form_widget(styleForm.customGlow, {attr: {'data-style-preview-target': 'glowToggle'}}) }}
        {{ form_widget(styleForm.glowEnabled, {attr: {'data-style-preview-target': 'glow'}}) }}
    </div>
    <p class="text-xs text-base-content/60">
        Unchecked = inherit from collection / your organizer defaults. Glow is auto-derived from the button color.
    </p>
</div>
```

- [ ] **Step 8: Render the panel in the event form template**

In `templates/admin/event/form.html.twig`, change the body so the style child renders in the right column and is excluded from the generic dump. Replace:

```twig
        <div class="card-body grid gap-4 lg:grid-cols-2">
            {{ form_widget(form) }}
        </div>
```

with:

```twig
        <div class="card-body grid gap-4 lg:grid-cols-2">
            <div class="space-y-4">
                {{ form_row(form.name) }}
                {{ form_row(form.description) }}
                {{ form_row(form.eventDate) }}
                {{ form_row(form.startTime) }}
                {{ form_row(form.endTime) }}
                {{ form_row(form.timezone) }}
                {{ form_row(form.logoFile) }}
                {{ form_row(form.collection) }}
                {% if form.owner is defined %}{{ form_row(form.owner) }}{% endif %}
                {% if form.notificationsEnabled is defined %}{{ form_row(form.notificationsEnabled) }}{% endif %}
            </div>
            <div>
                {% include '_partials/_style_fields.html.twig' with {styleForm: form.style, inherited: form.style.vars.inherited ?? null} only %}
            </div>
        </div>
        {{ form_widget(form) }} {# renders any remaining/hidden fields (CSRF); already-rendered fields are skipped #}
```

*(Note: the `inherited` option is available in the child view as `form.style.vars.inherited`. Symfony copies configured options into `vars` only when passed via `setDefined`/`vars` — verify the option surfaces; if not, pass `inherited` from the controller into the template render array instead and thread it through. Simplest robust route: add `'styleInherited' => $inherited` to the controller's `render(...)` for `admin/event/form.html.twig` and use that in the include.)*

Adopt the robust route: in `EventController::edit`/`new`, add `'styleInherited' => $inherited` to the `render('admin/event/form.html.twig', [...])` array, and in the template use `{ styleForm: form.style, inherited: styleInherited }`.

- [ ] **Step 9: Render the panel in the collection form template**

In `templates/admin/collection/form.html.twig`, add after the existing fields, inside the form:

```twig
        <div class="mt-4">
            {% include '_partials/_style_fields.html.twig' with {styleForm: form.style, inherited: styleInherited} only %}
        </div>
```
and pass `'styleInherited' => $inherited` from `EventCollectionController`.

- [ ] **Step 10: Run the functional test**

Run: `vendor/bin/phpunit tests/Functional/Admin/EventStyleEditTest.php`
Expected: PASS.

- [ ] **Step 11: Stage + propose commit**

```bash
git add src/Form/EventType.php src/Form/EventCollectionType.php \
  src/Controller/Admin/EventController.php src/Controller/Admin/EventCollectionController.php \
  templates/admin/event/form.html.twig templates/admin/collection/form.html.twig \
  templates/_partials/_style_fields.html.twig tests/Functional/Admin/EventStyleEditTest.php
```
Proposed message: `54 - wire style fields into event and collection admin forms`

---

## Task 8: Organizer profile styling (account page)

**Files:**
- Create: `src/Form/OrganizerProfileType.php`
- Modify: `src/Controller/Account/AccountController.php`, `templates/account/show.html.twig`
- Test: `tests/Functional/Account/OrganizerProfileStyleTest.php`

**Interfaces:**
- Consumes: `OrganizerProfile` (Task 3), `OrganizerProfileRepository` (Task 3), `StyleSettingsType` (Task 6), `EntityManagerInterface`.
- Produces: route `account_change_style` (`POST /account/style`), `styleForm` on `account_show`.

- [ ] **Step 1: Write the failing functional test**

Create `tests/Functional/Account/OrganizerProfileStyleTest.php` (model on `tests/Functional/Account/` conventions). Log in as an organizer, GET `/account`, submit the styling form with `organizer_profile[style][customFontColor]=1` + `organizer_profile[style][fontColor]=#222222`, assert an `OrganizerProfile` row exists for the user with `getStyle()->getFontColor() === '#222222'`.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Account/OrganizerProfileStyleTest.php`
Expected: FAIL — route/form absent.

- [ ] **Step 3: Create OrganizerProfileType**

```php
<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\OrganizerProfile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<OrganizerProfile>
 */
final class OrganizerProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('style', StyleSettingsType::class, ['label' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => OrganizerProfile::class]);
    }
}
```

- [ ] **Step 4: Add the form + POST action to AccountController**

Add imports:

```php
use App\Entity\OrganizerProfile;
use App\Form\OrganizerProfileType;
use App\Repository\OrganizerProfileRepository;
```

Inject `private readonly OrganizerProfileRepository $organizerProfiles,` into the constructor.

Add a helper:

```php
    private function loadOrCreateProfile(User $user): OrganizerProfile
    {
        return $this->organizerProfiles->findOneBy(['user' => $user]) ?? new OrganizerProfile($user);
    }
```

In `show()`, before the render, build the form and pass it:

```php
        $profile   = $this->loadOrCreateProfile($user);
        $styleForm = $this->createForm(OrganizerProfileType::class, $profile, [
            'action' => $this->generateUrl('account_change_style'),
        ]);
```
add `'styleForm' => $styleForm,` to the render array.

Add the action:

```php
    #[Route('/account/style', name: 'account_change_style', methods: ['POST'])]
    public function changeStyle(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $profile = $this->loadOrCreateProfile($user);

        $form = $this->createForm(OrganizerProfileType::class, $profile);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Styling update failed — check the form.');

            return $this->redirectToRoute('account_show');
        }

        if ($profile->getId() === null) {
            $this->em->persist($profile);
        }
        $this->em->flush();

        $this->addFlash('success', 'Styling defaults updated.');

        return $this->redirectToRoute('account_show');
    }
```

- [ ] **Step 5: Add the section to the account template**

In `templates/account/show.html.twig`, before the closing `</div>` of the main wrapper:

```twig
    {% if styleForm is defined %}
    <section class="space-y-3">
        <h2 class="text-lg font-medium">Branding defaults</h2>
        <p class="text-sm text-base-content/70">
            These apply to your events unless a collection or event overrides them.
        </p>
        {{ form(styleForm) }}
    </section>
    {% endif %}
```

*(The organizer tier is the top of the chain, so no inherited panel is needed here; the shared partial is used inside the admin event/collection forms. For the account page the default `form(styleForm)` rendering is acceptable — a preview panel here is optional polish, out of scope for phase 1.)*

- [ ] **Step 6: Run the functional test**

Run: `vendor/bin/phpunit tests/Functional/Account/OrganizerProfileStyleTest.php`
Expected: PASS.

- [ ] **Step 7: Stage + propose commit**

```bash
git add src/Form/OrganizerProfileType.php src/Controller/Account/AccountController.php \
  templates/account/show.html.twig tests/Functional/Account/OrganizerProfileStyleTest.php
```
Proposed message: `54 - let organizers set profile-level styling defaults on the account page`

---

## Task 9: `style-preview` Stimulus controller

**Files:**
- Create: `assets/controllers/style_preview_controller.js`
- Modify: `templates/_partials/_style_fields.html.twig` (already wired in Task 7)

**Interfaces:**
- Consumes: the `data-style-preview-target` elements + `data-style-preview-inherited-*-value` from the partial.
- Produces: live-updates the preview card CSS variables on input/change.

- [ ] **Step 1: Write the controller**

```javascript
import { Controller } from '@hotwired/stimulus';

// Mirrors App\Service\Style\ResolvedStyle: luminance-based button contrast and
// the accent-derived radial glow. Kept in sync with the PHP derivation.
export default class extends Controller {
    static targets = ['card', 'heading', 'button', 'input', 'toggle', 'glow', 'glowToggle'];
    static values = {
        inheritedFont: String,
        inheritedBackground: String,
        inheritedButton: String,
    };

    connect() {
        this.render();
    }

    // wired via data-action in the partial, or listen on the root
    inputTargetConnected() { this.render(); }

    render() {
        const font   = this.effective('fontColor', this.inheritedFontValue);
        const bg      = this.effective('backgroundColor', this.inheritedBackgroundValue);
        const button = this.effective('buttonColor', this.inheritedButtonValue);
        const glow   = this.glowHasTarget && this.glowToggleTarget.checked && this.glowTarget.checked;

        const card = this.cardTarget;
        if (font) { card.style.setProperty('--color-base-content', font); this.headingTarget.style.color = font; }
        if (button) {
            card.style.setProperty('--color-primary', button);
            this.buttonTarget.style.backgroundColor = button;
            this.buttonTarget.style.color = this.contrast(button);
        }
        const base = bg || '#FFFFFF';
        if (glow && button) {
            const [r, g, b] = this.hexToRgb(button);
            card.style.background = `radial-gradient(circle, rgba(${r}, ${g}, ${b}, 0.4), ${base})`;
        } else {
            card.style.background = base;
        }
    }

    effective(field, inherited) {
        const toggle = this.toggleTargets.find((t) => t.dataset.field === field);
        const input  = this.inputTargets.find((t) => t.dataset.field === field);
        if (toggle && toggle.checked && input) { return input.value; }
        return inherited || null;
    }

    hexToRgb(hex) {
        return [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
    }

    contrast(hex) {
        const [r, g, b] = this.hexToRgb(hex);
        const lum = 0.2126 * (r / 255) + 0.7152 * (g / 255) + 0.0722 * (b / 255);
        return lum > 0.5 ? '#000000' : '#FFFFFF';
    }
}
```

Wire live updates by adding to the partial's root element (Task 7 file):

```twig
data-action="input->style-preview#render change->style-preview#render"
```
(add alongside `{{ stimulus_controller('style-preview') }}`).

- [ ] **Step 2: Build assets + manual verify**

```bash
docker compose up -d
```
Open an event edit page. Toggle each override checkbox and change colors; confirm the preview card updates live (font color, button color + legible label contrast, glow gradient). Uncheck an override → preview falls back to the inherited value.

- [ ] **Step 3: Stage + propose commit**

```bash
git add assets/controllers/style_preview_controller.js templates/_partials/_style_fields.html.twig
```
Proposed message: `54 - add live style preview Stimulus controller`

---

## Task 10: Full-suite green + quality gates

**Files:** none (verification task).

- [ ] **Step 1: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: all green (recall PHPUnit fails on any deprecation/notice/warning).

- [ ] **Step 2: Run the quality gates**

```bash
vendor/bin/phpstan analyse
vendor/bin/phpcs
bin/console doctrine:schema:validate --env=test
vendor/bin/rector process --dry-run
vendor/bin/grumphp run
```
Expected: all pass. Fix any `phpmnd` magic-number findings by naming constants (already done for luminance/alpha in `ResolvedStyle`).

- [ ] **Step 3: Propose final state**

Summarize staged changes and the per-task commit messages for the user to commit.

---

## Follow-up phases (tracking)

Phase 1 (this plan) is one of four sub-projects under issue #54. The remaining phases each get their own spec → plan → implementation cycle and reuse this phase's `StyleResolver`, `StyleSettings`/`ResolvedStyle` shapes, `StyleSettingsType`, and the `_base` wrapper:

- **Phase 2 — Banner / hero image:** Vich-managed banner (separate from logo), storage disk, derivative generation, rendered in the styled wrapper.
- **Phase 3 — Typography:** curated self-hosted fonts via AssetMapper (no CSP exists today, so no CSP work needed), added as a `fontFamily` field on `StyleSettings`.
- **Phase 4 — Invitation-email branding:** flow the resolved tokens into the HTML email path as **inlined** styles (email clients don't honor CSS custom properties).

Tracking mechanism (to set up): convert issue #54 into a phase checklist and open linked follow-up issues for phases 2–4.

---

## Self-Review

- **Spec coverage:** data model (Tasks 1, 3) ✓; resolution (Tasks 2, 4) ✓; rendering landing+gallery (Task 5) ✓; admin UI Event/Collection/Organizer + override checkboxes (Tasks 6–8) ✓; live preview (Task 9) ✓; authorization — Event/Collection ride existing voters via the existing edit actions (Task 7 reuses `edit()` which already calls `denyAccessUnlessGranted`; **verify** the edit actions' existing gate covers the added field — no new voter needed), organizer profile scoped to `getUser()` (Task 8) ✓; testing + gates (Task 10) ✓; migration (Task 3) ✓.
- **Deviation from spec §2 (documented):** system defaults are null-omit (inherit silk theme), not concrete hex, because silk's `--color-primary` is dark navy — concrete hex defaults would recolor existing events, violating the "visually unchanged" AC. `ResolvedStyle` fields are nullable accordingly.
- **Placeholder scan:** the two "read an existing test first" notes (Tasks 5, 7, 8) are setup guidance, not code placeholders — the assertions are concrete. The `inherited` view-var caveat in Task 7 Step 8 is resolved by the "robust route" (`styleInherited` render var). Drop the unused `HEX_BASE` const in Task 2.
- **Type consistency:** `getStyle(): StyleSettings`, `resolve(Event): ResolvedStyle`, `resolveChain(?StyleSettings ...)`, `profileStyleFor(User): ?StyleSettings`, field names `fontColor/backgroundColor/buttonColor/glowEnabled` + `custom*` checkboxes — consistent across Tasks 1–9.
