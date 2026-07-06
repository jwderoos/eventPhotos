# Branded header in the styling preview (#97) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Render a miniature of the branded public header (logo + label + "powered by" line) inside the live styling-preview card on the event and collection admin forms, restyled by the existing Stimulus controller.

**Architecture:** A new `BrandPreviewResolver` service turns the event/collection **owner** into a `BrandPreview` DTO (`label`, `logoUrl`) — resolving the owner's brand and picking a slug-independent logo URL (`account_brand_logo` for self, `admin_user_brand_logo` for the admin-editing-another case). The four admin form actions pass this DTO to the shared `_style_fields.html.twig` partial, which renders a static mini-header inside the already-styled preview card. No JavaScript changes.

**Tech Stack:** PHP 8.5, Symfony 8, Twig, PHPUnit 13. PHP attributes only.

## Global Constraints

- Branch: `feature/97-branded-header-in-styling-preview` (already created).
- Every commit message must contain the issue number `97`.
- `phpstan` level 10, `phpcs` PSR-12, `phpmnd` (no magic numbers in `src/`), `phpcpd` (50-line/100-token duplication), `rector`, `doctrine:schema:validate` all gate every commit via GrumPHP. No schema change in this feature.
- PHP attributes over annotations. Services are `final readonly` where they hold only deps.
- Do **not** run `git commit` unless explicitly asked — stage only. (User commits themselves.) The "Commit" steps below describe the intended commit; stage the listed files and propose the message rather than committing.
- Run PHP/Composer/`bin/console`/`vendor/bin/*` on the **host**.
- After each task, run `vendor/bin/grumphp run` yourself — do not trust a subagent's self-report.

---

### Task 1: `BrandResolver::resolveForOwner` refactor

Extract owner-based brand resolution so `BrandPreviewResolver` can resolve without an `Event`. Behavior of the existing `resolve(Event)` is unchanged.

**Files:**
- Modify: `src/Service/Brand/BrandResolver.php`
- Test: `tests/Unit/Service/Brand/BrandResolverTest.php`

**Interfaces:**
- Consumes: `App\Repository\OrganizerProfileRepository::findOneBy`, `App\Service\Brand\ResolvedBrand`, `App\Entity\User`.
- Produces: `BrandResolver::resolveForOwner(User $owner): ?ResolvedBrand`; `resolve(Event $event): ?ResolvedBrand` (unchanged signature, now delegates).

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Service/Brand/BrandResolverTest.php`:

```php
    public function testResolveForOwnerReturnsNullWhenNoProfile(): void
    {
        $owner = new User('owner@example.com', 'Owner');

        $repo = $this->createStub(OrganizerProfileRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $this->assertNull(new BrandResolver($repo)->resolveForOwner($owner));
    }

    public function testResolveForOwnerReturnsBrandFromProfile(): void
    {
        $owner = new User('owner@example.com', 'Owner');
        $profile = new OrganizerProfile($owner);
        $profile->setBrandLabel('Acme Corp');
        $profile->setBrandLogoFilename('acme-abc123.png');
        $profile->setBrandUrl('https://acme.example');

        $repo = $this->createStub(OrganizerProfileRepository::class);
        $repo->method('findOneBy')->willReturn($profile);

        $resolved = new BrandResolver($repo)->resolveForOwner($owner);

        $this->assertInstanceOf(ResolvedBrand::class, $resolved);
        $this->assertSame('Acme Corp', $resolved->label);
        $this->assertTrue($resolved->hasLogo);
        $this->assertSame('https://acme.example', $resolved->url);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testResolveForOwner tests/Unit/Service/Brand/BrandResolverTest.php`
Expected: FAIL — `Call to undefined method App\Service\Brand\BrandResolver::resolveForOwner()`.

- [ ] **Step 3: Refactor the implementation**

Replace the body of `src/Service/Brand/BrandResolver.php` (keep the `namespace`, imports, and constructor). Add the `User` import:

```php
use App\Entity\Event;
use App\Entity\User;
use App\Repository\OrganizerProfileRepository;
```

Replace the `resolve` method with:

```php
    public function resolveForOwner(User $owner): ?ResolvedBrand
    {
        $profile = $this->profiles->findOneBy(['user' => $owner]);

        if ($profile === null || !$profile->hasBrand()) {
            return null;
        }

        return new ResolvedBrand(
            label:   $profile->getBrandLabel(),
            hasLogo: $profile->getBrandLogoFilename() !== null,
            url:     $profile->getBrandUrl(),
        );
    }

    public function resolve(Event $event): ?ResolvedBrand
    {
        return $this->resolveForOwner($event->getOwner());
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Service/Brand/BrandResolverTest.php`
Expected: PASS (all existing + 2 new tests).

- [ ] **Step 5: Commit**

```bash
git add src/Service/Brand/BrandResolver.php tests/Unit/Service/Brand/BrandResolverTest.php
# propose commit: "97 - refactor: BrandResolver::resolveForOwner for owner-based brand resolution"
```

---

### Task 2: `BrandPreview` DTO + `BrandPreviewResolver` service

The DTO handed to the template and the service that builds it from an owner, including the slug-independent logo-URL choice.

**Files:**
- Create: `src/Service/Brand/BrandPreview.php`
- Create: `src/Service/Brand/BrandPreviewResolver.php`
- Test: `tests/Unit/Service/Brand/BrandPreviewResolverTest.php`

**Interfaces:**
- Consumes: `BrandResolver::resolveForOwner(User): ?ResolvedBrand` (Task 1); `Symfony\Component\Routing\Generator\UrlGeneratorInterface::generate`; `Symfony\Bundle\SecurityBundle\Security::getUser`; `App\Entity\User`.
- Produces:
  - `App\Service\Brand\BrandPreview` — `final readonly` with `public ?string $label` and `public ?string $logoUrl`.
  - `App\Service\Brand\BrandPreviewResolver::forOwner(User $owner): ?BrandPreview` (null when the owner has no brand).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/Brand/BrandPreviewResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Brand;

use App\Entity\OrganizerProfile;
use App\Entity\User;
use App\Repository\OrganizerProfileRepository;
use App\Service\Brand\BrandPreview;
use App\Service\Brand\BrandPreviewResolver;
use App\Service\Brand\BrandResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class BrandPreviewResolverTest extends TestCase
{
    private function ownerWithId(string $email, int $id): User
    {
        $owner = new User($email, 'Owner');
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($owner, $id);

        return $owner;
    }

    private function resolverFor(?OrganizerProfile $profile, ?User $currentUser, UrlGeneratorInterface $urls): BrandPreviewResolver
    {
        $repo = $this->createStub(OrganizerProfileRepository::class);
        $repo->method('findOneBy')->willReturn($profile);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($currentUser);

        return new BrandPreviewResolver(new BrandResolver($repo), $urls, $security);
    }

    public function testReturnsNullWhenOwnerHasNoBrand(): void
    {
        $owner = $this->ownerWithId('owner@example.com', 1);
        $urls = $this->createStub(UrlGeneratorInterface::class);

        $this->assertNull($this->resolverFor(null, $owner, $urls)->forOwner($owner));
    }

    public function testSelfOwnerWithLogoUsesAccountRoute(): void
    {
        $owner = $this->ownerWithId('owner@example.com', 7);
        $profile = new OrganizerProfile($owner);
        $profile->setBrandLabel('Acme');
        $profile->setBrandLogoFilename('acme.png');

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects($this->once())
            ->method('generate')
            ->with('account_brand_logo')
            ->willReturn('/account/brand-logo');

        $preview = $this->resolverFor($profile, $owner, $urls)->forOwner($owner);

        $this->assertInstanceOf(BrandPreview::class, $preview);
        $this->assertSame('Acme', $preview->label);
        $this->assertSame('/account/brand-logo', $preview->logoUrl);
    }

    public function testAdminEditingAnotherOwnerUsesAdminUserRoute(): void
    {
        $owner = $this->ownerWithId('owner@example.com', 42);
        $admin = $this->ownerWithId('admin@example.com', 1);
        $profile = new OrganizerProfile($owner);
        $profile->setBrandLabel('Acme');
        $profile->setBrandLogoFilename('acme.png');

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects($this->once())
            ->method('generate')
            ->with('admin_user_brand_logo', ['id' => 42])
            ->willReturn('/admin/users/42/brand-logo');

        $preview = $this->resolverFor($profile, $admin, $urls)->forOwner($owner);

        $this->assertInstanceOf(BrandPreview::class, $preview);
        $this->assertSame('/admin/users/42/brand-logo', $preview->logoUrl);
    }

    public function testLabelOnlyBrandHasNullLogoUrl(): void
    {
        $owner = $this->ownerWithId('owner@example.com', 5);
        $profile = new OrganizerProfile($owner);
        $profile->setBrandLabel('Acme');

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects($this->never())->method('generate');

        $preview = $this->resolverFor($profile, $owner, $urls)->forOwner($owner);

        $this->assertInstanceOf(BrandPreview::class, $preview);
        $this->assertSame('Acme', $preview->label);
        $this->assertNull($preview->logoUrl);
    }
}
```

Note: `User::$id` is a private Doctrine-managed field; the test sets it via reflection to exercise the id-comparison branch without a DB.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/Brand/BrandPreviewResolverTest.php`
Expected: FAIL — `Class "App\Service\Brand\BrandPreview" not found` (or `BrandPreviewResolver` not found).

- [ ] **Step 3: Create the DTO**

Create `src/Service/Brand/BrandPreview.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Brand;

final readonly class BrandPreview
{
    public function __construct(
        public ?string $label,
        public ?string $logoUrl,
    ) {
    }
}
```

- [ ] **Step 4: Create the resolver**

Create `src/Service/Brand/BrandPreviewResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Brand;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class BrandPreviewResolver
{
    public function __construct(
        private BrandResolver $brands,
        private UrlGeneratorInterface $urls,
        private Security $security,
    ) {
    }

    public function forOwner(User $owner): ?BrandPreview
    {
        $brand = $this->brands->resolveForOwner($owner);

        if ($brand === null) {
            return null;
        }

        $logoUrl = null;
        if ($brand->hasLogo) {
            $current = $this->security->getUser();
            $logoUrl = $current instanceof User && $current->getId() === $owner->getId()
                ? $this->urls->generate('account_brand_logo')
                : $this->urls->generate('admin_user_brand_logo', ['id' => $owner->getId()]);
        }

        return new BrandPreview(label: $brand->label, logoUrl: $logoUrl);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Service/Brand/BrandPreviewResolverTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Service/Brand/BrandPreview.php src/Service/Brand/BrandPreviewResolver.php tests/Unit/Service/Brand/BrandPreviewResolverTest.php
# propose commit: "97 - add BrandPreview DTO + BrandPreviewResolver (owner brand + slug-independent logo URL)"
```

---

### Task 3: Render the branded header in the preview card

Wire the resolver into the four form actions, thread `brandPreview` through the two form includes, and render the static mini-header in the shared partial. Functional tests assert the header appears in the preview card.

**Files:**
- Modify: `src/Controller/Admin/EventController.php` (constructor; `new` render ~113-118; `edit` render ~155-164)
- Modify: `src/Controller/Admin/EventCollectionController.php` (constructor; `new` render ~75-80; `edit` render ~108-113)
- Modify: `templates/_partials/_style_fields.html.twig`
- Modify: `templates/admin/event/form.html.twig:45`
- Modify: `templates/admin/collection/form.html.twig:31`
- Test: `tests/Functional/Admin/StylePreviewBrandTest.php` (create)

**Interfaces:**
- Consumes: `BrandPreviewResolver::forOwner(User): ?BrandPreview` (Task 2); `App\Service\Brand\BrandPreview` (`.label`, `.logoUrl`).
- Produces: template contract — the include `_style_fields.html.twig` accepts an optional `brandPreview` (a `BrandPreview` or `null`).

- [ ] **Step 1: Write the failing functional test**

Create `tests/Functional/Admin/StylePreviewBrandTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\OrganizerProfile;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class StylePreviewBrandTest extends WebTestCase
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

    /** @param list<string> $roles */
    private function seedUser(string $email, array $roles): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User($email, 'Seeded');
        foreach ($roles as $role) {
            $user->addRole($role);
        }
        $user->setPassword($hasher->hashPassword($user, 'placeholder placeholder'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function brandFor(User $owner): void
    {
        $profile = new OrganizerProfile($owner);
        $profile->setBrandLabel('Acme Corp');
        $profile->setBrandLogoFilename('acme.png');
        $profile->setBrandUrl('https://acme.example');

        $this->em->persist($profile);
        $this->em->flush();
    }

    public function testNewEventFormShowsOwnBrandHeaderInPreview(): void
    {
        $organizer = $this->seedUser('org-brand@example.com', ['ROLE_ORGANIZER']);
        $this->brandFor($organizer);
        $this->client->loginUser($organizer);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/events/new');
        self::assertResponseIsSuccessful();

        $card = $crawler->filter('[data-style-preview-target="card"]');
        $this->assertStringContainsString('Acme Corp', $card->html());
        $this->assertStringContainsString('powered by: EventPhotos by JWdR', $card->html());
        $this->assertGreaterThan(
            0,
            $card->filter('img[src="/account/brand-logo"]')->count(),
            'own-brand logo img not found in preview card',
        );
    }

    public function testNewEventFormShowsDefaultHeaderWhenNoBrand(): void
    {
        $organizer = $this->seedUser('org-nobrand@example.com', ['ROLE_ORGANIZER']);
        $this->client->loginUser($organizer);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/events/new');
        self::assertResponseIsSuccessful();

        $card = $crawler->filter('[data-style-preview-target="card"]');
        $this->assertStringContainsString('EventPhotos by JWdR', $card->html());
        $this->assertStringNotContainsString('powered by: EventPhotos by JWdR', $card->html());
        $this->assertSame(0, $card->filter('img')->count(), 'no brand img expected without a brand');
    }

    public function testAdminEditingAnotherOwnersEventShowsOwnerBrandViaAdminRoute(): void
    {
        $admin = $this->seedUser('admin-preview@example.com', ['ROLE_ADMIN']);
        $owner = $this->seedUser('owner-preview@example.com', ['ROLE_ORGANIZER']);
        $this->brandFor($owner);

        $event = new Event(
            'preview-owner-slug',
            'Owned Event',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );
        $this->em->persist($event);
        $this->em->flush();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/events/' . $event->getId() . '/edit');
        self::assertResponseIsSuccessful();

        $card = $crawler->filter('[data-style-preview-target="card"]');
        $this->assertStringContainsString('Acme Corp', $card->html());
        $this->assertGreaterThan(
            0,
            $card->filter('img[src="/admin/users/' . $owner->getId() . '/brand-logo"]')->count(),
            'owner-brand logo img (admin route) not found in preview card',
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Admin/StylePreviewBrandTest.php`
Expected: FAIL — the preview card contains neither "Acme Corp" nor a brand `<img>` (partial not updated yet).

- [ ] **Step 3: Update the shared partial**

In `templates/_partials/_style_fields.html.twig`, replace the card block (lines 12-18) with a version that renders the static mini-header inside the card target, above the `<h3>`. The header params comment at the top should also mention `brandPreview`:

```twig
{# params: styleForm (FormView for the 'style' child), inherited (ResolvedStyle|null), brandPreview (BrandPreview|null) #}
```

Card block:

```twig
    <div data-style-preview-target="card" data-theme="silk"
         class="card bg-base-100 text-base-content shadow-sm overflow-hidden border border-base-300">
        <div class="card-body items-center text-center gap-3">
            {# Static miniature of the public header (templates/public/_base.html.twig).
               Brand is per-organizer, static relative to the color inputs, so it is
               rendered once server-side; the Stimulus controller only restyles the card. #}
            <div class="flex flex-col items-center gap-0.5">
                {% if brandPreview is defined and brandPreview %}
                    <span class="flex items-center gap-2">
                        {% if brandPreview.logoUrl %}
                            <img src="{{ brandPreview.logoUrl }}"
                                 alt="{{ brandPreview.label ?? 'Brand logo' }}"
                                 class="h-6 w-auto object-contain" />
                        {% endif %}
                        {% if brandPreview.label %}
                            <span class="text-base font-semibold tracking-tight">{{ brandPreview.label }}</span>
                        {% endif %}
                    </span>
                    <span class="text-xs opacity-60">powered by: EventPhotos by JWdR</span>
                {% else %}
                    <span class="text-base font-semibold tracking-tight">EventPhotos by JWdR</span>
                {% endif %}
            </div>

            <h3 class="text-xl font-bold text-base-content">Preview</h3>
            <button type="button" class="btn btn-primary">Meld je aan</button>
        </div>
    </div>
```

- [ ] **Step 4: Thread `brandPreview` through the two form includes**

`templates/admin/event/form.html.twig:45`:

```twig
                {% include '_partials/_style_fields.html.twig' with {styleForm: form.style, inherited: styleInherited, brandPreview: brandPreview} only %}
```

`templates/admin/collection/form.html.twig:31`:

```twig
                {% include '_partials/_style_fields.html.twig' with {styleForm: form.style, inherited: styleInherited, brandPreview: brandPreview} only %}
```

- [ ] **Step 5: Inject the resolver into `EventController` and pass `brandPreview`**

In `src/Controller/Admin/EventController.php`, add the import near the other `use App\Service\...` lines:

```php
use App\Service\Brand\BrandPreviewResolver;
```

Add a constructor dependency (append to the promoted-property list):

```php
        private readonly BrandPreviewResolver $brandPreview,
```

In `new()`, the render array (currently keys `form`, `event`, `mode`, `styleInherited`) — add:

```php
            'brandPreview'   => $this->brandPreview->forOwner($user),
```

In `edit()`, the render array — add:

```php
            'brandPreview'     => $this->brandPreview->forOwner($event->getOwner()),
```

- [ ] **Step 6: Inject the resolver into `EventCollectionController` and pass `brandPreview`**

In `src/Controller/Admin/EventCollectionController.php`, add the import:

```php
use App\Service\Brand\BrandPreviewResolver;
```

Add the constructor dependency:

```php
        private readonly BrandPreviewResolver $brandPreview,
```

In `new()`, add to the render array:

```php
            'brandPreview'   => $this->brandPreview->forOwner($user),
```

In `edit()`, add to the render array:

```php
            'brandPreview'   => $this->brandPreview->forOwner($collection->getOwner()),
```

- [ ] **Step 7: Run the functional test to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Admin/StylePreviewBrandTest.php`
Expected: PASS (3 tests).

- [ ] **Step 8: Run the full suite + quality gates**

Run: `vendor/bin/phpunit`
Expected: PASS (no deprecations/notices/warnings).

Run: `vendor/bin/grumphp run`
Expected: PASS (phpstan level 10, phpcs, phpmnd, phpcpd, rector, schema:validate all green).

- [ ] **Step 9: Commit**

```bash
git add src/Controller/Admin/EventController.php src/Controller/Admin/EventCollectionController.php templates/_partials/_style_fields.html.twig templates/admin/event/form.html.twig templates/admin/collection/form.html.twig tests/Functional/Admin/StylePreviewBrandTest.php
# propose commit: "97 - render branded header in the event/collection styling preview - closes #97"
```

---

## Manual verification (after Task 3)

1. `docker compose up -d`; log in as an organizer that has a brand label + logo configured (Account → brand).
2. Visit `/admin/events/new` and `/admin/collections/new`: the preview card shows the logo + label + "powered by" line.
3. Toggle font/background/button colors and glow — the label text and glow restyle live; the logo stays put. Confirm it reads the same as the real public event header.
4. As an organizer with **no** brand, confirm the preview shows the plain `EventPhotos by JWdR` header and no logo.
5. As an admin, edit another organizer's event that has a brand — confirm that organizer's logo/label appears in the preview.

## Self-Review notes

- **Spec coverage:** owner-brand source (Task 2 `forOwner` + owner passed in Task 3); powered-by kept (Task 3 partial); static/no-link (partial has no `<a>`); slug-independent logo URL two-way branch (Task 2); no-brand fallback (partial `else`); no JS change (none present); BrandResolver refactor (Task 1). All covered.
- **Type consistency:** `forOwner(User): ?BrandPreview`, `BrandPreview.label`/`.logoUrl`, `resolveForOwner(User): ?ResolvedBrand` used consistently across tasks and tests.
- **No schema change**, so `doctrine:migrations:diff` is not part of this plan.
