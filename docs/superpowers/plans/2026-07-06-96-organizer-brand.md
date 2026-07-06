# Organizer Brand in Public Header — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an organizer attach a per-organizer brand (label and/or logo, optional homepage link) shown in the public event-page header, and relabel the platform to "EventPhotos by JWdR".

**Architecture:** Brand fields are added directly to the existing per-organizer `OrganizerProfile` entity (made Vich-uploadable for the logo), edited on `/account`. A `BrandResolver` turns an `Event` into a nullable `ResolvedBrand` DTO passed to the public event templates alongside `resolvedStyle`. The brand logo is streamed to anonymous visitors via an event-scoped public route and previewed to the organizer via an `/account` route.

**Tech Stack:** PHP 8.5 / Symfony 8 / Doctrine ORM 3 / Vich Uploader / Flysystem / Twig / PHPUnit 13.

**Spec:** `docs/superpowers/specs/2026-07-06-96-organizer-brand-design.md`

## Global Constraints

- **Commits:** Do NOT run `git commit`. Each task's final step stages files with `git add` and records the proposed one-line commit message; the human commits. All proposed messages start with `96 - `.
- **Branch:** work happens on `feature/96-organizer-brand` (already checked out).
- **Quality gates (run before considering any task done):** `vendor/bin/phpstan analyse` (level 10), `vendor/bin/phpcs` (PSR-12), and — after entity/config/migration land — `bin/console doctrine:schema:validate`. Do not trust a subagent's self-report; run these yourself. No magic numbers in `src/` (phpmnd) — name constants.
- **PHP attributes only** — never annotations.
- **Migrations are generated, never hand-written:** use `bin/console doctrine:migrations:diff`; edit only `getDescription()`.
- **Run PHP/Composer/console on the host** (PHP 8.5 via Homebrew), not in Docker.
- **Platform label is the literal string `EventPhotos by JWdR`** (initials literal). Subline literal: `powered by: EventPhotos by JWdR`. Footer: `© <year> EventPhotos by JWdR`.
- **PNG transparency must be preserved** — the logo bytes are streamed verbatim; never composite a background.
- **Public brand-logo route must not touch the session** — no flash, no CSRF, no `getSession()` (per the CLAUDE.md public-route session discipline).

---

### Task 1: Brand fields on `OrganizerProfile` + storage wiring + migration

**Files:**
- Modify: `src/Entity/OrganizerProfile.php`
- Modify: `config/packages/flysystem.yaml`
- Modify: `config/packages/vich_uploader.yaml`
- Create: `migrations/VersionYYYYMMDDHHMMSS.php` (generated)
- Test: `tests/Unit/Entity/OrganizerProfileTest.php`

**Interfaces:**
- Produces:
  - `OrganizerProfile::getBrandLabel(): ?string` / `setBrandLabel(?string): void`
  - `OrganizerProfile::getBrandLogoFilename(): ?string` / `setBrandLogoFilename(?string): void`
  - `OrganizerProfile::getBrandLogoUpdatedAt(): ?DateTimeImmutable`
  - `OrganizerProfile::getBrandLogoFile(): ?File` / `setBrandLogoFile(?File): void` (bumps `brandLogoUpdatedAt`)
  - `OrganizerProfile::getBrandUrl(): ?string` / `setBrandUrl(?string): void`
  - `OrganizerProfile::hasBrand(): bool` — true iff `brandLabel !== null` OR `brandLogoFilename !== null`
  - Flysystem storage service `brand_logos_storage`; Vich mapping `brand_logo`.

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/Entity/OrganizerProfileTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\OrganizerProfile;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class OrganizerProfileTest extends TestCase
{
    private function profile(): OrganizerProfile
    {
        return new OrganizerProfile(new User('owner@example.com', 'Owner'));
    }

    public function testHasBrandIsFalseWhenNeitherLabelNorLogoSet(): void
    {
        $this->assertFalse($this->profile()->hasBrand());
    }

    public function testHasBrandIsTrueWithLabelOnly(): void
    {
        $profile = $this->profile();
        $profile->setBrandLabel('Acme Corp');

        $this->assertTrue($profile->hasBrand());
    }

    public function testHasBrandIsTrueWithLogoOnly(): void
    {
        $profile = $this->profile();
        $profile->setBrandLogoFilename('acme-abc123.png');

        $this->assertTrue($profile->hasBrand());
    }

    public function testHasBrandIsTrueWithBoth(): void
    {
        $profile = $this->profile();
        $profile->setBrandLabel('Acme Corp');
        $profile->setBrandLogoFilename('acme-abc123.png');

        $this->assertTrue($profile->hasBrand());
    }

    public function testBrandUrlRoundTrips(): void
    {
        $profile = $this->profile();
        $profile->setBrandUrl('https://acme.example');

        $this->assertSame('https://acme.example', $profile->getBrandUrl());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Entity/OrganizerProfileTest.php`
Expected: FAIL — `Call to undefined method App\Entity\OrganizerProfile::setBrandLabel()` (or `hasBrand()`).

- [ ] **Step 3: Add the brand fields to `OrganizerProfile`**

In `src/Entity/OrganizerProfile.php`, add imports at the top (after the existing `use` block):

```php
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Attribute as Vich;
```

Add `#[Vich\Uploadable]` to the class attributes (alongside `#[ORM\Entity(...)]`, `#[ORM\Table(...)]`, `#[ORM\UniqueConstraint(...)]`):

```php
#[ORM\Entity(repositoryClass: OrganizerProfileRepository::class)]
#[ORM\Table(name: 'organizer_profiles')]
#[ORM\UniqueConstraint(name: 'uniq_organizer_profiles_user', columns: ['user_id'])]
#[Vich\Uploadable]
class OrganizerProfile
```

Add these properties inside the class body (after the existing `$style` property):

```php
    #[ORM\Column(type: Types::STRING, length: 120, nullable: true)]
    private ?string $brandLabel = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $brandLogoFilename = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $brandLogoUpdatedAt = null;

    #[ORM\Column(type: Types::STRING, length: 512, nullable: true)]
    #[Assert\Url(protocols: ['http', 'https'])]
    private ?string $brandUrl = null;

    #[Vich\UploadableField(mapping: 'brand_logo', fileNameProperty: 'brandLogoFilename')]
    #[Assert\File(
        maxSize: '2M',
        mimeTypes: ['image/png', 'image/jpeg'],
        mimeTypesMessage: 'Please upload a PNG or JPEG image.',
    )]
    private ?File $brandLogoFile = null;
```

Add these methods (before the closing brace of the class):

```php
    public function getBrandLabel(): ?string
    {
        return $this->brandLabel;
    }

    public function setBrandLabel(?string $brandLabel): void
    {
        $this->brandLabel = $brandLabel === '' ? null : $brandLabel;
    }

    public function getBrandLogoFilename(): ?string
    {
        return $this->brandLogoFilename;
    }

    public function setBrandLogoFilename(?string $brandLogoFilename): void
    {
        $this->brandLogoFilename = $brandLogoFilename;
    }

    public function getBrandLogoUpdatedAt(): ?DateTimeImmutable
    {
        return $this->brandLogoUpdatedAt;
    }

    public function getBrandLogoFile(): ?File
    {
        return $this->brandLogoFile;
    }

    public function setBrandLogoFile(?File $brandLogoFile): void
    {
        $this->brandLogoFile = $brandLogoFile;

        if ($brandLogoFile instanceof File) {
            $this->brandLogoUpdatedAt = new DateTimeImmutable();
        }
    }

    public function getBrandUrl(): ?string
    {
        return $this->brandUrl;
    }

    public function setBrandUrl(?string $brandUrl): void
    {
        $this->brandUrl = $brandUrl === '' ? null : $brandUrl;
    }

    public function hasBrand(): bool
    {
        return $this->brandLabel !== null || $this->brandLogoFilename !== null;
    }
```

- [ ] **Step 4: Run the unit test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Entity/OrganizerProfileTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Add the Flysystem storage disk**

In `config/packages/flysystem.yaml`, add under `flysystem.storages` (mirroring `event_logos_storage`):

```yaml
        brand_logos_storage:
            adapter: 'local'
            options:
                directory: '%kernel.project_dir%/var/uploads/brand-logos'
```

- [ ] **Step 6: Add the Vich mapping**

In `config/packages/vich_uploader.yaml`, add under `vich_uploader.mappings` (mirroring `event_logo`):

```yaml
        brand_logo:
            uri_prefix: ''
            upload_destination: brand_logos_storage
            namer: Vich\UploaderBundle\Naming\SmartUniqueNamer
            delete_on_remove: true
            delete_on_update: true
```

- [ ] **Step 7: Generate the migration**

Run: `bin/console doctrine:migrations:diff`
Expected: a new `migrations/VersionYYYYMMDDHHMMSS.php` adding `brand_label`, `brand_logo_filename`, `brand_logo_updated_at`, `brand_url` to `organizer_profiles`. Open it and confirm it contains ONLY those four `ALTER TABLE organizer_profiles ADD ...` statements (no unrelated drift). Optionally set a clear `getDescription()`.

- [ ] **Step 8: Apply the migration to dev + test DBs**

Run:
```bash
bin/console doctrine:migrations:migrate --no-interaction
bin/console doctrine:database:create --env=test --if-not-exists
bin/console doctrine:migrations:migrate --env=test --no-interaction
```
Expected: both migrate cleanly.

- [ ] **Step 9: Validate schema + static analysis**

Run:
```bash
bin/console doctrine:schema:validate
vendor/bin/phpstan analyse src/Entity/OrganizerProfile.php
vendor/bin/phpunit tests/Unit/Entity/OrganizerProfileTest.php
```
Expected: schema mapping + sync OK; phpstan clean; tests PASS.

- [ ] **Step 10: Stage and propose commit message**

Run: `git add src/Entity/OrganizerProfile.php config/packages/flysystem.yaml config/packages/vich_uploader.yaml migrations/ tests/Unit/Entity/OrganizerProfileTest.php`
Proposed message: `96 - add per-organizer brand fields + logo storage to OrganizerProfile`

---

### Task 2: `BrandResolver` + `ResolvedBrand` DTO

**Files:**
- Create: `src/Service/Brand/ResolvedBrand.php`
- Create: `src/Service/Brand/BrandResolver.php`
- Test: `tests/Unit/Service/Brand/BrandResolverTest.php`

**Interfaces:**
- Consumes: `OrganizerProfile::hasBrand()`, `getBrandLabel()`, `getBrandLogoFilename()`, `getBrandUrl()` (Task 1); `OrganizerProfileRepository::findOneBy(['user' => $owner])`; `Event::getOwner()`.
- Produces:
  - `App\Service\Brand\ResolvedBrand` — `final readonly`, constructor `(?string $label, bool $hasLogo, ?string $url)`, public promoted props.
  - `App\Service\Brand\BrandResolver::resolve(Event $event): ?ResolvedBrand` — null when no profile or `!hasBrand()`.

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/Service/Brand/BrandResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Brand;

use App\Entity\Event;
use App\Entity\OrganizerProfile;
use App\Entity\User;
use App\Repository\OrganizerProfileRepository;
use App\Service\Brand\BrandResolver;
use App\Service\Brand\ResolvedBrand;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BrandResolverTest extends TestCase
{
    private function event(User $owner): Event
    {
        return new Event(
            'some-slug',
            'Some Event',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );
    }

    public function testReturnsNullWhenNoProfile(): void
    {
        $owner = new User('owner@example.com', 'Owner');

        $repo = $this->createMock(OrganizerProfileRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $resolver = new BrandResolver($repo);

        $this->assertNull($resolver->resolve($this->event($owner)));
    }

    public function testReturnsNullWhenProfileHasNoBrand(): void
    {
        $owner = new User('owner@example.com', 'Owner');
        $profile = new OrganizerProfile($owner);

        $repo = $this->createMock(OrganizerProfileRepository::class);
        $repo->method('findOneBy')->willReturn($profile);

        $resolver = new BrandResolver($repo);

        $this->assertNull($resolver->resolve($this->event($owner)));
    }

    public function testResolvesLabelLogoAndUrl(): void
    {
        $owner = new User('owner@example.com', 'Owner');
        $profile = new OrganizerProfile($owner);
        $profile->setBrandLabel('Acme Corp');
        $profile->setBrandLogoFilename('acme-abc123.png');
        $profile->setBrandUrl('https://acme.example');

        $repo = $this->createMock(OrganizerProfileRepository::class);
        $repo->method('findOneBy')->willReturn($profile);

        $resolved = (new BrandResolver($repo))->resolve($this->event($owner));

        $this->assertInstanceOf(ResolvedBrand::class, $resolved);
        $this->assertSame('Acme Corp', $resolved->label);
        $this->assertTrue($resolved->hasLogo);
        $this->assertSame('https://acme.example', $resolved->url);
    }

    public function testResolvesLabelOnlyWithoutLogoOrUrl(): void
    {
        $owner = new User('owner@example.com', 'Owner');
        $profile = new OrganizerProfile($owner);
        $profile->setBrandLabel('Acme Corp');

        $repo = $this->createMock(OrganizerProfileRepository::class);
        $repo->method('findOneBy')->willReturn($profile);

        $resolved = (new BrandResolver($repo))->resolve($this->event($owner));

        $this->assertInstanceOf(ResolvedBrand::class, $resolved);
        $this->assertSame('Acme Corp', $resolved->label);
        $this->assertFalse($resolved->hasLogo);
        $this->assertNull($resolved->url);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/Brand/BrandResolverTest.php`
Expected: FAIL — `Class "App\Service\Brand\BrandResolver" not found`.

- [ ] **Step 3: Create the `ResolvedBrand` DTO**

Create `src/Service/Brand/ResolvedBrand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Brand;

final readonly class ResolvedBrand
{
    public function __construct(
        public ?string $label,
        public bool $hasLogo,
        public ?string $url,
    ) {
    }
}
```

- [ ] **Step 4: Create the `BrandResolver`**

Create `src/Service/Brand/BrandResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Brand;

use App\Entity\Event;
use App\Repository\OrganizerProfileRepository;

final readonly class BrandResolver
{
    public function __construct(
        private OrganizerProfileRepository $profiles,
    ) {
    }

    public function resolve(Event $event): ?ResolvedBrand
    {
        $profile = $this->profiles->findOneBy(['user' => $event->getOwner()]);

        if ($profile === null || !$profile->hasBrand()) {
            return null;
        }

        return new ResolvedBrand(
            label: $profile->getBrandLabel(),
            hasLogo: $profile->getBrandLogoFilename() !== null,
            url: $profile->getBrandUrl(),
        );
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Service/Brand/BrandResolverTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Static analysis**

Run: `vendor/bin/phpstan analyse src/Service/Brand tests/Unit/Service/Brand`
Expected: clean.

- [ ] **Step 7: Stage and propose commit message**

Run: `git add src/Service/Brand tests/Unit/Service/Brand`
Proposed message: `96 - add BrandResolver + ResolvedBrand DTO for per-organizer brand`

---

### Task 3: Public brand-logo serve route

**Files:**
- Modify: `src/Controller/Public/EventController.php`
- Test: `tests/Functional/Public/EventBrandLogoServeTest.php`

**Interfaces:**
- Consumes: `Event::getOwner()`, `OrganizerProfileRepository` (already available via the container), `OrganizerProfile::getBrandLogoFilename()` / `getBrandLogoUpdatedAt()` (Task 1); Flysystem `brand_logos_storage`.
- Produces: route `public_event_brand_logo` at `GET /e/{slug}/brand-logo.png`.

**Note on constants:** phpmnd forbids magic numbers in `src/`. Reuse or define named constants for cache max-age.

- [ ] **Step 1: Write the failing functional test**

Create `tests/Functional/Public/EventBrandLogoServeTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\OrganizerProfile;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EventBrandLogoServeTest extends WebTestCase
{
    public function testServesBrandLogoBytesForConfiguredBrand(): void
    {
        $client = self::createClient();
        $c = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var FilesystemOperator $storage */
        $storage = $c->get('brand_logos_storage');

        $owner = new User('brand-serve@example.com', 'Owner');
        $owner->setPassword('x');
        $event = new Event(
            'brand-serve-slug',
            'Brand Serve Event',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );

        $profile = new OrganizerProfile($owner);
        $profile->setBrandLogoFilename('brand-serve.png');
        // 1x1 transparent PNG
        $storage->write('brand-serve.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
        ));

        $em->persist($owner);
        $em->persist($event);
        $em->persist($profile);
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/brand-serve-slug/brand-logo.png');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
    }

    public function testReturns404WhenNoBrandLogo(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('brand-nologo@example.com', 'Owner');
        $owner->setPassword('x');
        $event = new Event(
            'brand-nologo-slug',
            'No Logo Event',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );
        $em->persist($owner);
        $em->persist($event);
        $em->flush();

        $client->request(Request::METHOD_GET, '/e/brand-nologo-slug/brand-logo.png');

        self::assertResponseStatusCodeSame(404);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Public/EventBrandLogoServeTest.php`
Expected: FAIL — 404 for the first test (route does not exist yet).

- [ ] **Step 3: Add the serve action + dependencies to `Public\EventController`**

Add imports (if not already present):

```php
use App\Repository\OrganizerProfileRepository;
```

Add two constructor-injected dependencies to the existing constructor property list:

```php
        #[Autowire(service: 'brand_logos_storage')]
        private readonly FilesystemOperator $brandLogosStorage,
        private readonly OrganizerProfileRepository $organizerProfiles,
```

Add a class constant near the existing `private const` declarations:

```php
    private const int BRAND_LOGO_MAX_AGE = 300;
```

Add the action (place it near the other public routes, e.g. after `photoNeighbor`):

```php
    #[Route(
        '/e/{slug}/brand-logo.png',
        name: 'public_event_brand_logo',
        requirements: ['slug' => '[a-z0-9-]+'],
        methods: ['GET'],
    )]
    public function brandLogo(string $slug, Request $request): Response
    {
        $event   = $this->resolve($slug);
        $profile = $this->organizerProfiles->findOneBy(['user' => $event->getOwner()]);

        $filename = $profile?->getBrandLogoFilename();
        if ($profile === null || $filename === null) {
            throw new NotFoundHttpException();
        }

        $updatedAt = $profile->getBrandLogoUpdatedAt();
        $etag = sha1(sprintf(
            '%d|%s',
            (int) $profile->getId(),
            $updatedAt instanceof DateTimeImmutable ? $updatedAt->format('U') : '-',
        ));

        $response = new Response();
        $response->setEtag($etag);
        $response->setPublic();
        $response->setMaxAge(self::BRAND_LOGO_MAX_AGE);

        if ($response->isNotModified($request)) {
            return $response;
        }

        try {
            $contents = $this->brandLogosStorage->read($filename);
        } catch (FilesystemException) {
            throw new NotFoundHttpException();
        }

        $response->setContent($contents);
        $response->headers->set('Content-Type', $this->brandLogoMime($filename));

        return $response;
    }

    private function brandLogoMime(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'png'         => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default       => 'application/octet-stream',
        };
    }
```

(`FilesystemException`, `NotFoundHttpException`, `DateTimeImmutable`, `Response`, `Request`, `Autowire`, `FilesystemOperator` are already imported in this controller.)

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Public/EventBrandLogoServeTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Verify the route creates no session row**

Run: `vendor/bin/phpunit tests/Functional/Public/EventBrandLogoServeTest.php && grep -rn "getSession\|addFlash\|csrf_token" src/Controller/Public/EventController.php`
Expected: tests PASS; grep shows the `brandLogo` action introduces no session/flash/CSRF usage (pre-existing hits, if any, are unrelated to this route).

- [ ] **Step 6: Static analysis**

Run: `vendor/bin/phpstan analyse src/Controller/Public/EventController.php`
Expected: clean.

- [ ] **Step 7: Stage and propose commit message**

Run: `git add src/Controller/Public/EventController.php tests/Functional/Public/EventBrandLogoServeTest.php`
Proposed message: `96 - serve per-organizer brand logo on public event route`

---

### Task 4: Admin brand form + `/account` UI + organizer logo preview route

**Files:**
- Modify: `src/Form/OrganizerProfileType.php`
- Modify: `src/Controller/Account/AccountController.php`
- Modify: `templates/account/show.html.twig`
- Test: `tests/Functional/Account/OrganizerBrandTest.php`

**Interfaces:**
- Consumes: `OrganizerProfileType` (Task 1 fields); `AccountController::loadOrCreateProfile()` (existing private helper); `AccountController::changeStyle()` already persists the whole profile via `OrganizerProfileType`, so uploads flow through Vich with no new persist logic.
- Produces:
  - Form fields `brandLabel`, `brandLogoFile`, `brandUrl` on `OrganizerProfileType`.
  - Route `account_brand_logo` at `GET /account/brand-logo` serving the current user's brand logo.

- [ ] **Step 1: Write the failing functional test**

Create `tests/Functional/Account/OrganizerBrandTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Entity\OrganizerProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class OrganizerBrandTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em = $em;
    }

    private function makeOrganizer(string $email): User
    {
        $u = new User($email, 'Organizer');
        $u->addRole('ROLE_ORGANIZER');
        $u->setPassword('$2y$10$qqqqqqqqqqqqqqqqqqqqqq');
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    public function testOrganizerCanSetBrandLabelAndUrl(): void
    {
        $user = $this->makeOrganizer('brand-set@example.com');
        $this->client->loginUser($user);

        $crawler = $this->client->request(Request::METHOD_GET, '/account');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="/account/style"]')->form();
        $form['organizer_profile[brandLabel]'] = 'Acme Corp';
        $form['organizer_profile[brandUrl]'] = 'https://acme.example';

        $this->client->submit($form);
        self::assertResponseRedirects('/account');

        $userId = $user->getId();
        $this->em->clear();
        /** @var User $reloaded */
        $reloaded = $this->em->find(User::class, $userId);
        $profile = $this->em->getRepository(OrganizerProfile::class)->findOneBy(['user' => $reloaded]);

        $this->assertInstanceOf(OrganizerProfile::class, $profile);
        $this->assertSame('Acme Corp', $profile->getBrandLabel());
        $this->assertSame('https://acme.example', $profile->getBrandUrl());
        $this->assertTrue($profile->hasBrand());
    }

    public function testBrandFieldsVisibleOnAccountPage(): void
    {
        $user = $this->makeOrganizer('brand-visible@example.com');
        $this->client->loginUser($user);

        $this->client->request(Request::METHOD_GET, '/account');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="organizer_profile[brandLabel]"]');
        self::assertSelectorExists('input[name="organizer_profile[brandUrl]"]');
    }

    public function testBrandLogoPreviewRouteReturns404WhenUnset(): void
    {
        $user = $this->makeOrganizer('brand-nopreview@example.com');
        $this->client->loginUser($user);

        $this->client->request(Request::METHOD_GET, '/account/brand-logo');
        self::assertResponseStatusCodeSame(404);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Account/OrganizerBrandTest.php`
Expected: FAIL — brand fields not on the form / `/account/brand-logo` route missing.

- [ ] **Step 3: Add brand fields to `OrganizerProfileType`**

Replace the `buildForm` body in `src/Form/OrganizerProfileType.php` and add imports:

```php
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Vich\UploaderBundle\Form\Type\VichImageType;
```

```php
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('brandLabel', TextType::class, [
            'required' => false,
            'label'    => 'Brand label',
            'help'     => 'Shown in the header of your public event pages.',
        ]);

        $builder->add('brandLogoFile', VichImageType::class, [
            'required'      => false,
            'label'         => 'Brand logo (PNG or JPEG, max 2 MB)',
            'help'          => 'Transparent PNG recommended so it sits on any background.',
            'allow_delete'  => true,
            'download_uri'  => false,
            'image_uri'     => false,
        ]);

        $builder->add('brandUrl', UrlType::class, [
            'required'         => false,
            'label'            => 'Brand homepage URL',
            'default_protocol' => null,
        ]);

        $builder->add('style', StyleSettingsType::class, ['label' => false]);
    }
```

- [ ] **Step 4: Add the organizer logo-preview route to `AccountController`**

In `src/Controller/Account/AccountController.php`, add imports as needed:

```php
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
```

Add a constructor-injected storage dependency (append to the existing constructor):

```php
        #[Autowire(service: 'brand_logos_storage')]
        private readonly FilesystemOperator $brandLogosStorage,
```

Add the action:

```php
    #[Route('/account/brand-logo', name: 'account_brand_logo', methods: ['GET'])]
    public function brandLogo(): Response
    {
        /** @var User $user */
        $user    = $this->getUser();
        $profile = $this->loadOrCreateProfile($user);

        $filename = $profile->getBrandLogoFilename();
        if ($filename === null) {
            throw $this->createNotFoundException();
        }

        try {
            $contents = $this->brandLogosStorage->read($filename);
        } catch (FilesystemException) {
            throw $this->createNotFoundException();
        }

        $response = new Response($contents);
        $response->headers->set(
            'Content-Type',
            str_ends_with(strtolower($filename), '.png') ? 'image/png' : 'image/jpeg',
        );
        $response->headers->set('Cache-Control', 'private, max-age=300');

        return $response;
    }
```

Note: `loadOrCreateProfile()` persists a fresh profile only inside `changeStyle`; here it may return a transient profile with no id — that is fine because a transient profile has `brandLogoFilename === null` and returns 404. If phpmnd flags `300`, extract a `private const int LOGO_PREVIEW_MAX_AGE = 300;`.

- [ ] **Step 5: Render brand fields + preview on the account page**

In `templates/account/show.html.twig`, the `{{ form(styleForm) }}` inside the "Branding defaults" section already renders ALL `OrganizerProfileType` fields (including the new brand fields) automatically. Add a current-logo preview directly above that `{{ form(styleForm) }}` call, still inside the `{% if styleForm is defined %}` section:

```twig
        {% if brandLogoSet %}
            <div class="flex items-center gap-3">
                <span class="text-sm font-medium">Current brand logo:</span>
                <img src="{{ path('account_brand_logo') }}"
                     alt="Brand logo"
                     class="h-16 w-16 object-contain border rounded bg-base-200" />
            </div>
        {% endif %}
        {{ form(styleForm) }}
```

Then pass `brandLogoSet` from the `AccountController::show()` render call — add to the render array (reuse the profile already loaded in `show()` for `styleForm`; do not call `loadOrCreateProfile()` twice):

```php
            'brandLogoSet' => $profile->getBrandLogoFilename() !== null,
```

Note: `show()` currently inlines `$this->loadOrCreateProfile($user)` in the `createForm` call. Extract it to a local `$profile = $this->loadOrCreateProfile($user);` first, pass `$profile` to `createForm(OrganizerProfileType::class, $profile, ...)`, and reuse it for `brandLogoSet`.

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Account/OrganizerBrandTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Regression — existing style test still green**

Run: `vendor/bin/phpunit tests/Functional/Account/OrganizerProfileStyleTest.php`
Expected: PASS (existing style form still works; field name prefix unchanged).

- [ ] **Step 8: Static analysis**

Run: `vendor/bin/phpstan analyse src/Form/OrganizerProfileType.php src/Controller/Account/AccountController.php`
Expected: clean.

- [ ] **Step 9: Stage and propose commit message**

Run: `git add src/Form/OrganizerProfileType.php src/Controller/Account/AccountController.php templates/account/show.html.twig tests/Functional/Account/OrganizerBrandTest.php`
Proposed message: `96 - add brand fields to account branding form + logo preview route`

---

### Task 5: Public header/footer rendering + wire brand into event pages

**Files:**
- Modify: `src/Controller/Public/EventController.php`
- Modify: `templates/public/_base.html.twig`
- Test: `tests/Functional/Public/EventBrandHeaderTest.php`

**Interfaces:**
- Consumes: `BrandResolver::resolve()` + `ResolvedBrand` (Task 2); route `public_event_brand_logo` (Task 3).
- Produces: `brand` template variable on `landing` + `photos` renders; branded header + relabeled footer in `_base.html.twig`.

- [ ] **Step 1: Write the failing functional test**

Create `tests/Functional/Public/EventBrandHeaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\OrganizerProfile;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class EventBrandHeaderTest extends WebTestCase
{
    private function makeEvent(EntityManagerInterface $em, string $slug, string $email): Event
    {
        $owner = new User($email, 'Owner');
        $owner->setPassword('x');
        $event = new Event(
            $slug,
            'Branded Event',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );
        $em->persist($owner);
        $em->persist($event);
        $em->flush();

        return $event;
    }

    public function testDefaultPlatformLabelWhenNoBrand(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $event = $this->makeEvent($em, 'brand-default-slug', 'brand-default@example.com');

        $client->request(Request::METHOD_GET, '/e/' . $event->getSlug());
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('EventPhotos by JWdR', $html);
        $this->assertStringContainsString('© ' . date('Y') . ' EventPhotos by JWdR', $html);
        $this->assertStringNotContainsString('powered by: EventPhotos by JWdR', $html);
    }

    public function testBrandedHeaderWithLabelLogoAndUrl(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $event = $this->makeEvent($em, 'brand-full-slug', 'brand-full@example.com');

        $profile = new OrganizerProfile($event->getOwner());
        $profile->setBrandLabel('Acme Corp');
        $profile->setBrandLogoFilename('acme.png');
        $profile->setBrandUrl('https://acme.example');
        $em->persist($profile);
        $em->flush();

        $crawler = $client->request(Request::METHOD_GET, '/e/' . $event->getSlug());
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Acme Corp', $html);
        $this->assertStringContainsString('powered by: EventPhotos by JWdR', $html);

        // Brand is linked to the homepage
        $link = $crawler->filter('header a[href="https://acme.example"]');
        $this->assertGreaterThan(0, $link->count(), 'brand link to homepage not found');
        // Logo points at the public serve route
        $img = $crawler->filter('header img[src$="/brand-logo.png"]');
        $this->assertGreaterThan(0, $img->count(), 'brand logo img not found');
    }

    public function testBrandedHeaderWithoutUrlRendersNoAnchor(): void
    {
        $client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $event = $this->makeEvent($em, 'brand-nourl-slug', 'brand-nourl@example.com');

        $profile = new OrganizerProfile($event->getOwner());
        $profile->setBrandLabel('Acme Corp');
        // no brandUrl
        $em->persist($profile);
        $em->flush();

        $crawler = $client->request(Request::METHOD_GET, '/e/' . $event->getSlug());
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Acme Corp', $html);
        $this->assertStringContainsString('powered by: EventPhotos by JWdR', $html);

        // The brand label must NOT be wrapped in an anchor (no dead link).
        $brandBlock = $crawler->filter('[data-brand-primary]');
        $this->assertGreaterThan(0, $brandBlock->count(), 'brand primary block not found');
        $this->assertSame(0, $brandBlock->filter('a')->count(), 'brand must render without an anchor when URL is empty');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Public/EventBrandHeaderTest.php`
Expected: FAIL — footer/header still say "Event Photos"; no `powered by` / `data-brand-primary`.

- [ ] **Step 3: Inject `BrandResolver` and pass `brand` to the event renders**

In `src/Controller/Public/EventController.php`, add the import:

```php
use App\Service\Brand\BrandResolver;
```

Append to the constructor:

```php
        private readonly BrandResolver $brandResolver,
```

In `landing()`, add to the render array:

```php
            'brand' => $this->brandResolver->resolve($event),
```

In `photos()`, add to the render array (the one that also passes `resolvedStyle`):

```php
            'brand' => $this->brandResolver->resolve($event),
```

- [ ] **Step 4: Update `_base.html.twig` header + footer**

In `templates/public/_base.html.twig`, replace the `<header>…</header>` block with:

```twig
        <header>
            <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-4">
                {% if brand is defined and brand %}
                    <div class="flex flex-col gap-0.5">
                        {% set brand_content %}
                            <span class="flex items-center gap-2">
                                {% if brand.hasLogo %}
                                    <img src="{{ path('public_event_brand_logo', {slug: event.slug}) }}"
                                         alt="{{ brand.label ?? 'Brand logo' }}"
                                         class="h-8 w-auto object-contain" />
                                {% endif %}
                                {% if brand.label %}
                                    <span class="text-lg font-semibold tracking-tight">{{ brand.label }}</span>
                                {% endif %}
                            </span>
                        {% endset %}

                        {% if brand.url %}
                            <a href="{{ brand.url }}" data-brand-primary rel="noopener" target="_blank">{{ brand_content }}</a>
                        {% else %}
                            <span data-brand-primary>{{ brand_content }}</span>
                        {% endif %}

                        <span class="text-xs opacity-60">powered by: EventPhotos by JWdR</span>
                    </div>
                {% else %}
                    <a href="{{ path('public_home') }}" class="text-lg font-semibold tracking-tight">
                        EventPhotos by JWdR
                    </a>
                {% endif %}
            </div>
        </header>
```

Replace the footer inner text:

```twig
        <footer>
            <div class="mx-auto max-w-5xl px-4 py-6 text-sm opacity-70">
                © {{ "now"|date("Y") }} EventPhotos by JWdR
            </div>
        </footer>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Public/EventBrandHeaderTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Regression — other public pages still render the default label**

Run: `vendor/bin/phpunit tests/Functional/Public/EventStylingRenderTest.php tests/Functional/Public/EventPhotosGalleryTest.php`
Expected: PASS — home/photos pages that don't pass `brand` fall through to the "EventPhotos by JWdR" default without error.

- [ ] **Step 7: Static analysis + full suite**

Run:
```bash
vendor/bin/phpstan analyse
vendor/bin/phpunit
```
Expected: phpstan clean; full suite green.

- [ ] **Step 8: Stage and propose commit message**

Run: `git add src/Controller/Public/EventController.php templates/public/_base.html.twig tests/Functional/Public/EventBrandHeaderTest.php`
Proposed message: `96 - render organizer brand in public header; relabel platform to EventPhotos by JWdR`

---

## Final verification (after all tasks)

- [ ] Run the full quality gate: `vendor/bin/grumphp run`
      Expected: all tasks pass (phpstan L10, phpcs PSR-12, phpmnd, phpcpd, rector, securitychecker, `doctrine:schema:validate`).
- [ ] Manual smoke (optional, via `docker compose up -d`): set a brand label + logo + URL on `/account`, then load `/e/{slug}` and confirm the linked brand + "powered by" subline render, and `/e/{slug}/photos` matches. Load an event whose owner has no brand and confirm the "EventPhotos by JWdR" default.
- [ ] Confirm every spec acceptance criterion (§ "Acceptance criteria" in the issue) is covered.
- [ ] Propose the squash/PR commit summary to the human (do not commit).
