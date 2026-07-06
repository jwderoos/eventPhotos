# Admin parity with self-service account settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin view and edit, for any user, the organizer styling+brand and linked SSO identities the user can already edit on `/account`.

**Architecture:** Extract the one-line `loadOrCreateProfile` from `AccountController` into a shared `OrganizerProfileProvider` service, then reuse the existing `OrganizerProfileType` form and identity-unlink logic from the admin `UserController`, scoped to a *target* user (route `{id}`) instead of `getUser()`. New sections render on the existing `/admin/users/{id}/edit` page. Mirrors the established `UserMailConfigType` self+admin precedent.

**Tech Stack:** PHP 8.5 / Symfony 8 / Doctrine ORM 3 / PostgreSQL 16 / Vich Uploader / Flysystem / Twig+Tailwind/DaisyUI / PHPUnit 13.

## Global Constraints

- **PHP attributes only** — no annotations. `declare(strict_types=1);` in every new PHP file.
- **Password is out of scope** — admins never set another user's password; the existing "Send password reset email" action is the admin equivalent. Do not add a password field.
- **Identities: view + unlink only** — no "link identity as admin" (OAuth authenticates the actor, not an arbitrary user). No Google-link button on the admin page.
- **Every mutating admin route (`POST`/`PUT`/`PATCH`/`DELETE`) MUST carry `#[Audited(...)]` or `#[AuditIgnore]`** — enforced by `tests/Functional/Audit/AuditCoverageTest::testEveryMutatingAdminRouteIsAnnotated`. The two new POST routes get `#[Audited]`. The GET brand-logo route is not mutating and needs neither.
- **No admin controller may be invokable** (`AuditCoverageTest::testNoAdminControllerIsInvokable`) — keep all new actions as methods on `UserController`.
- **No magic numbers in `src/`** (phpmnd) — the cache-max-age uses a named class constant.
- **No hand-written migrations** — this feature adds **no** schema changes (all brand columns shipped with #96); if `doctrine:schema:validate` complains, something is wrong, do not paper over it with a hand migration.
- **Public-route session discipline does not apply here** — every route in this plan is under `/admin` (organizer/admin-gated), so flashes/CSRF/sessions are expected and correct.
- **Gate conventions (carried from #54/#75/#96 ledgers — apply in every task):**
  - PHPUnit assertions use `$this->assert...`, NOT `self::assert...` (rector `PreferPHPUnitThisCallRector` rewrites `self`→`$this`). Symfony `WebTestCase` helpers (`assertResponseIsSuccessful`/`assertResponseRedirects`/`assertResponseStatusCodeSame`/`assertSelectorExists`/`assertSelectorTextContains`) stay `self::` (static trait). Run `vendor/bin/rector process --dry-run` over touched test files and apply what it says before reporting DONE.
  - Prefer `createStub` over `createMock` for doubles you only `willReturn` (no `expects`) — avoids "no expectations configured" PHPUnit notices. Test output must be pristine (no notices/warnings/deprecations — PHPUnit is configured `failOnDeprecation`/`failOnNotice`/`failOnWarning`).
  - `OrganizerProfileRepository` is a non-final concrete class (de-final'd in #54) → it can be doubled with `createStub`.
  - `new User(string $email, string $displayName)` throws on empty email; always pass real args.
  - DomCrawler form field values are strings: `$form['x[y]'] = '1'`, never `= true`.
  - Run `vendor/bin/grumphp run` before reporting DONE. Do NOT trust a prior "grumphp green" claim — the controller re-verifies.

---

### Task 1: Shared `OrganizerProfileProvider` + refactor `AccountController`

**Files:**
- Create: `src/Service/Organizer/OrganizerProfileProvider.php`
- Create: `tests/Unit/Service/Organizer/OrganizerProfileProviderTest.php`
- Modify: `src/Controller/Account/AccountController.php` (replace private `loadOrCreateProfile` with the injected provider)

**Interfaces:**
- Consumes: `App\Repository\OrganizerProfileRepository` (`findOneBy(array): ?OrganizerProfile`), `App\Entity\OrganizerProfile` (ctor `new OrganizerProfile(User $user)`), `App\Entity\User`.
- Produces: `App\Service\Organizer\OrganizerProfileProvider::loadOrCreate(User $user): OrganizerProfile` — returns the persisted profile for the user, or a new **unpersisted** `OrganizerProfile` bound to the user when none exists. Task 2 injects this.

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/Service/Organizer/OrganizerProfileProviderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Organizer;

use App\Entity\OrganizerProfile;
use App\Entity\User;
use App\Repository\OrganizerProfileRepository;
use App\Service\Organizer\OrganizerProfileProvider;
use PHPUnit\Framework\TestCase;

final class OrganizerProfileProviderTest extends TestCase
{
    public function testReturnsExistingProfileWhenPresent(): void
    {
        $user = new User('has-profile@example.com', 'Has Profile');
        $existing = new OrganizerProfile($user);

        $repo = $this->createStub(OrganizerProfileRepository::class);
        $repo->method('findOneBy')->willReturn($existing);

        $provider = new OrganizerProfileProvider($repo);

        $this->assertSame($existing, $provider->loadOrCreate($user));
    }

    public function testReturnsNewUnpersistedProfileWhenAbsent(): void
    {
        $user = new User('no-profile@example.com', 'No Profile');

        $repo = $this->createStub(OrganizerProfileRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $provider = new OrganizerProfileProvider($repo);

        $profile = $provider->loadOrCreate($user);

        $this->assertInstanceOf(OrganizerProfile::class, $profile);
        $this->assertNull($profile->getId());
        $this->assertSame($user, $profile->getUser());
    }
}
```

> Note: confirm `OrganizerProfile` exposes `getUser(): User`. If the accessor is named differently, adjust the last assertion to the real accessor rather than adding one.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/Organizer/OrganizerProfileProviderTest.php`
Expected: FAIL — `Class "App\Service\Organizer\OrganizerProfileProvider" not found`.

- [ ] **Step 3: Create the provider**

Create `src/Service/Organizer/OrganizerProfileProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Organizer;

use App\Entity\OrganizerProfile;
use App\Entity\User;
use App\Repository\OrganizerProfileRepository;

final readonly class OrganizerProfileProvider
{
    public function __construct(private OrganizerProfileRepository $profiles)
    {
    }

    public function loadOrCreate(User $user): OrganizerProfile
    {
        return $this->profiles->findOneBy(['user' => $user]) ?? new OrganizerProfile($user);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Service/Organizer/OrganizerProfileProviderTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Refactor `AccountController` to use the provider**

In `src/Controller/Account/AccountController.php`:

1. Add the import: `use App\Service\Organizer\OrganizerProfileProvider;`
2. Add a constructor-promoted dependency (keep the existing ones; `OrganizerProfileRepository` is no longer used directly by the controller — remove the `use App\Repository\OrganizerProfileRepository;` import and the `private readonly OrganizerProfileRepository $organizerProfiles,` ctor param, and add):

```php
        private readonly OrganizerProfileProvider $organizerProfiles,
```

3. Delete the private method:

```php
    private function loadOrCreateProfile(User $user): OrganizerProfile
    {
        return $this->organizerProfiles->findOneBy(['user' => $user]) ?? new OrganizerProfile($user);
    }
```

4. Replace the three call sites `$this->loadOrCreateProfile($user)` (in `show()`, `changeStyle()`, `brandLogo()`) with `$this->organizerProfiles->loadOrCreate($user)`.

5. Remove the now-unused `use App\Entity\OrganizerProfile;` import **only if** nothing else in the file references `OrganizerProfile` after the edit (the `User` import stays — still used). Let `vendor/bin/phpcs`/rector tell you; do not leave an unused import.

This is behavior-preserving: same lookup, same "new when absent". The persist-if-new guard (`if ($profile->getId() === null) { $this->em->persist($profile); }`) stays in `changeStyle()` unchanged.

- [ ] **Step 6: Run the account regression suite + gates**

Run: `vendor/bin/phpunit tests/Functional/Account/ tests/Unit/Service/Organizer/`
Expected: PASS — existing `AccountControllerTest`, `OrganizerBrandTest`, `OrganizerProfileStyleTest` all green (no behavior change), plus the 2 new provider tests.

Run: `vendor/bin/rector process --dry-run` (over `src/Service/Organizer/`, `src/Controller/Account/AccountController.php`, `tests/Unit/Service/Organizer/`) and apply anything it reports.
Run: `vendor/bin/phpstan analyse` and `vendor/bin/grumphp run`.
Expected: all green, exit 0.

- [ ] **Step 7: Stage**

```bash
git add src/Service/Organizer/OrganizerProfileProvider.php \
        tests/Unit/Service/Organizer/OrganizerProfileProviderTest.php \
        src/Controller/Account/AccountController.php
```

(Do not commit — the user commits.)

---

### Task 2: Admin styling & brand — render, save, and serve the logo

**Files:**
- Modify: `src/Audit/AuditAction.php` (add one enum case)
- Modify: `src/Controller/Admin/UserController.php` (inject provider + brand storage; build `styleForm` in `edit()`; add `changeStyle()` POST + `brandLogo()` GET)
- Modify: `templates/admin/user/form.html.twig` (styling & brand section, edit mode only)
- Create: `tests/Functional/Admin/AdminUserStyleTest.php`

**Interfaces:**
- Consumes: `OrganizerProfileProvider::loadOrCreate(User): OrganizerProfile` (Task 1); `App\Form\OrganizerProfileType` (bound to `OrganizerProfile`, form block prefix `organizer_profile`, fields `brandLabel`/`brandLogoFile`/`brandUrl`/`style`); `App\Security\Voter\UserVoter::EDIT` and `::VIEW`; `brand_logos_storage` `FilesystemOperator`; `OrganizerProfile::getBrandLogoFilename(): ?string`.
- Produces: routes `admin_user_change_style` (`POST /admin/users/{id}/style`) and `admin_user_brand_logo` (`GET /admin/users/{id}/brand-logo`); template block `styleForm`/`brandLogoSet`. Task 3 adds the identities section to the same `edit()` render + template.

- [ ] **Step 1: Add the audit action case**

In `src/Audit/AuditAction.php`, add to the `User*` group (after `UserSendReset`):

```php
    case UserStyleChange = 'user.style_change';
```

No `category()`/`label()` change needed — `user.*` already maps to category "User", and `label()` derives "User style change" from the value.

- [ ] **Step 2: Write the failing functional test**

Create `tests/Functional/Admin/AdminUserStyleTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\OrganizerProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminUserStyleTest extends WebTestCase
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

    public function testAdminCanSetTargetBrandAndStyleCreatingProfile(): void
    {
        $admin  = $this->seedUser('admin@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('target@example.com', ['ROLE_ORGANIZER']);
        $targetId = $target->getId();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/users/' . $targetId . '/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="/style"]')->form();
        $form['organizer_profile[brandLabel]'] = 'Target Brand';
        $form['organizer_profile[brandUrl]']   = 'https://target.example';

        $this->client->submit($form);
        self::assertResponseRedirects('/admin/users/' . $targetId . '/edit');

        $this->em->clear();
        /** @var User $reloaded */
        $reloaded = $this->em->find(User::class, $targetId);
        $profile  = $this->em->getRepository(OrganizerProfile::class)->findOneBy(['user' => $reloaded]);

        $this->assertInstanceOf(OrganizerProfile::class, $profile);
        $this->assertSame('Target Brand', $profile->getBrandLabel());
        $this->assertSame('https://target.example', $profile->getBrandUrl());
    }

    public function testBrandLogoRouteReturns404WhenTargetHasNoLogo(): void
    {
        $admin  = $this->seedUser('admin2@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('target2@example.com', ['ROLE_ORGANIZER']);

        $this->client->loginUser($admin);
        $this->client->request(Request::METHOD_GET, '/admin/users/' . $target->getId() . '/brand-logo');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOrganizerIsForbiddenFromChangingAnotherUsersStyle(): void
    {
        $organizer = $this->seedUser('org@example.com', ['ROLE_ORGANIZER']);
        $target    = $this->seedUser('victim@example.com', ['ROLE_ORGANIZER']);

        $this->client->loginUser($organizer);
        $this->client->request(
            Request::METHOD_POST,
            '/admin/users/' . $target->getId() . '/style',
        );
        // /admin/** requires ROLE_ORGANIZER to enter, then UserVoter::EDIT denies non-admins.
        self::assertResponseStatusCodeSame(403);
    }

    public function testOrganizerIsForbiddenFromViewingAnotherUsersBrandLogo(): void
    {
        $organizer = $this->seedUser('org2@example.com', ['ROLE_ORGANIZER']);
        $target    = $this->seedUser('victim2@example.com', ['ROLE_ORGANIZER']);

        $this->client->loginUser($organizer);
        $this->client->request(
            Request::METHOD_GET,
            '/admin/users/' . $target->getId() . '/brand-logo',
        );
        self::assertResponseStatusCodeSame(403);
    }
}
```

> The style POST submitted via the crawler form carries the Symfony form CSRF token automatically. `disableReboot()` keeps the same EM so `find`/`clear` see the committed row.

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Admin/AdminUserStyleTest.php`
Expected: FAIL — no `/style` form on the edit page / route `admin_user_change_style` does not exist.

- [ ] **Step 4: Wire dependencies and the `edit()` render into `UserController`**

In `src/Controller/Admin/UserController.php`:

1. Add imports:

```php
use App\Form\OrganizerProfileType;
use App\Service\Organizer\OrganizerProfileProvider;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
```

2. Add a class constant at the top of the class body (before the constructor):

```php
    private const int BRAND_LOGO_PREVIEW_MAX_AGE = 300;
```

3. Add two constructor-promoted dependencies (append to the existing list):

```php
        private readonly OrganizerProfileProvider $organizerProfiles,
        #[Autowire(service: 'brand_logos_storage')]
        private readonly FilesystemOperator $brandLogosStorage,
```

4. In `edit()`, build the style form and pass it to the template. Immediately before the final `$status = ...; return $this->render(...)`, insert:

```php
        $profile = $this->organizerProfiles->loadOrCreate($target);
        $styleForm = $this->createForm(OrganizerProfileType::class, $profile, [
            'action' => $this->generateUrl('admin_user_change_style', ['id' => $target->getId()]),
        ]);
```

And extend the render array:

```php
        return $this->render('admin/user/form.html.twig', [
            'form'         => $form,
            'mode'         => 'edit',
            'target_id'    => $target->getId(),
            'target_email' => $target->getEmail(),
            'styleForm'    => $styleForm,
            'brandLogoSet' => $profile->getBrandLogoFilename() !== null,
        ], new Response(null, $status));
```

- [ ] **Step 5: Add the `changeStyle()` and `brandLogo()` actions**

Add these methods to `UserController` (e.g. after `sendReset()`):

```php
    #[Route(
        '/admin/users/{id}/style',
        name: 'admin_user_change_style',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[Audited(AuditAction::UserStyleChange, targetParam: 'id', targetType: 'User')]
    public function changeStyle(User $target, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(UserVoter::EDIT, $target);

        $profile = $this->organizerProfiles->loadOrCreate($target);
        $form = $this->createForm(OrganizerProfileType::class, $profile);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            // Redirect (3xx) would otherwise log a spurious audit row — suppress it.
            $this->audit->suppress();
            $this->addFlash('error', 'Styling update failed — check the form.');
            return new RedirectResponse('/admin/users/' . $target->getId() . '/edit');
        }

        if ($profile->getId() === null) {
            $this->em->persist($profile);
        }
        $this->em->flush();

        $this->audit->targetLabel($target->getEmail());
        $this->addFlash('success', 'Styling defaults updated.');
        return new RedirectResponse('/admin/users/' . $target->getId() . '/edit');
    }

    #[Route(
        '/admin/users/{id}/brand-logo',
        name: 'admin_user_brand_logo',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function brandLogo(User $target): Response
    {
        $this->denyAccessUnlessGranted(UserVoter::VIEW, $target);

        $profile  = $this->organizerProfiles->loadOrCreate($target);
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
        $response->headers->set('Cache-Control', 'private, max-age=' . self::BRAND_LOGO_PREVIEW_MAX_AGE);

        return $response;
    }
```

> `AuditAction`, `Audited`, `UserVoter`, `Route`, `RedirectResponse`, `Request`, `Response` are already imported in `UserController`. `changeStyle` relies on the form's built-in CSRF token (same as `AccountController::changeStyle`) — no manual `isCsrfTokenValid`. The `brandLogo` GET route is not mutating, so it needs no `#[Audited]`/`#[AuditIgnore]`.

- [ ] **Step 6: Add the styling & brand section to the template**

In `templates/admin/user/form.html.twig`, inside `{% block admin_main %}`, after the existing "Send password reset email" `{% if mode == 'edit' ... %}` form and before `{% endblock %}`, add:

```twig
    {% if styleForm is defined %}
        <section class="mt-8 space-y-3">
            <h2 class="text-lg font-medium">Styling &amp; brand</h2>
            <p class="text-sm text-base-content/70">
                Applies to this user's public event pages.
            </p>
            {% if brandLogoSet %}
                <div class="flex items-center gap-3">
                    <span class="text-sm font-medium">Current brand logo:</span>
                    <img src="{{ path('admin_user_brand_logo', {id: target_id}) }}"
                         alt="Brand logo"
                         class="h-16 w-16 object-contain border rounded bg-base-200" />
                </div>
            {% endif %}
            {{ form(styleForm) }}
        </section>
    {% endif %}
```

> `{{ form(styleForm) }}` renders its own `<form>` with `enctype=multipart/form-data` (it contains the Vich file field) and its CSRF token — no manual `form_start`. Guarding on `styleForm is defined` keeps the `new`-mode page (which does not pass it) unchanged.

- [ ] **Step 7: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Admin/AdminUserStyleTest.php`
Expected: PASS (4 tests).

- [ ] **Step 8: Run gates**

Run: `vendor/bin/phpunit tests/Functional/Admin/ tests/Functional/Audit/AuditCoverageTest.php` (regression: existing `UserCrudTest` + audit-coverage guard must stay green with the two new routes).
Run: `vendor/bin/rector process --dry-run` over `src/Controller/Admin/UserController.php`, `src/Audit/AuditAction.php`, `tests/Functional/Admin/AdminUserStyleTest.php`; apply what it reports.
Run: `vendor/bin/phpstan analyse` and `vendor/bin/grumphp run`.
Expected: all green, exit 0.

- [ ] **Step 9: Stage**

```bash
git add src/Audit/AuditAction.php \
        src/Controller/Admin/UserController.php \
        templates/admin/user/form.html.twig \
        tests/Functional/Admin/AdminUserStyleTest.php
```

---

### Task 3: Admin linked identities — view + unlink

**Files:**
- Modify: `src/Audit/AuditAction.php` (add one enum case)
- Modify: `src/Controller/Admin/UserController.php` (inject `UserIdentityRepository`; pass identities to `edit()` render; add `unlinkIdentity()` POST)
- Modify: `templates/admin/user/form.html.twig` (linked-identities section, edit mode only)
- Create: `tests/Functional/Admin/AdminUserIdentityTest.php`

**Interfaces:**
- Consumes: `App\Repository\UserIdentityRepository` (`find(int): ?UserIdentity`); `App\Entity\UserIdentity` (`getId()`, `getUser(): User`, `getProvider(): AuthProvider`, `getEmail(): ?string`, `getLinkedAt(): DateTimeImmutable`); `App\Entity\User` (`getIdentities()`, `removeIdentity(UserIdentity)`, `addIdentity(UserIdentity)`); `App\Security\Voter\UserIdentityVoter::UNLINK` (already grants `ROLE_ADMIN`); the `edit()` render from Task 2.
- Produces: route `admin_user_identity_unlink` (`POST /admin/users/{id}/identities/{identityId}/unlink`).

- [ ] **Step 1: Add the audit action case**

In `src/Audit/AuditAction.php`, after `UserStyleChange`:

```php
    case UserIdentityUnlink = 'user.identity_unlink';
```

- [ ] **Step 2: Write the failing functional test**

Create `tests/Functional/Admin/AdminUserIdentityTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\AuthProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminUserIdentityTest extends WebTestCase
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

    private function seedIdentity(User $user, string $subject): UserIdentity
    {
        $identity = new UserIdentity($user, AuthProvider::Google, $subject, $user->getEmail());
        $user->addIdentity($identity);
        $this->em->persist($identity);
        $this->em->flush();

        return $identity;
    }

    public function testAdminCanUnlinkTargetIdentity(): void
    {
        $admin  = $this->seedUser('admin@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('target@example.com', ['ROLE_ORGANIZER']);
        $identity = $this->seedIdentity($target, 'sub-admin-unlink');
        $identityId = $identity->getId();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/users/' . $target->getId() . '/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'target@example.com');

        $form = $crawler->filter('form[action$="/identities/' . $identityId . '/unlink"] button')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/users/' . $target->getId() . '/edit');

        $this->em->clear();
        $this->assertNull($this->em->find(UserIdentity::class, $identityId));
    }

    public function testUnlinkReturns404WhenIdentityBelongsToDifferentUser(): void
    {
        $admin  = $this->seedUser('admin2@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('target2@example.com', ['ROLE_ORGANIZER']);
        $other  = $this->seedUser('other2@example.com', ['ROLE_ORGANIZER']);
        $identity = $this->seedIdentity($other, 'sub-cross-user');

        $this->client->loginUser($admin);

        // identity belongs to $other but URL names $target — must 404, not unlink.
        $token = self::getContainer()->get('security.csrf.token_manager')
            ->getToken('admin-unlink-identity-' . $identity->getId())->getValue();

        $this->client->request(
            Request::METHOD_POST,
            '/admin/users/' . $target->getId() . '/identities/' . $identity->getId() . '/unlink',
            ['_token' => $token],
        );
        self::assertResponseStatusCodeSame(404);

        $this->em->clear();
        $this->assertNotNull($this->em->find(UserIdentity::class, $identity->getId()));
    }

    public function testOrganizerIsForbiddenFromUnlinkingAnotherUsersIdentity(): void
    {
        $organizer = $this->seedUser('org@example.com', ['ROLE_ORGANIZER']);
        $target    = $this->seedUser('victim@example.com', ['ROLE_ORGANIZER']);
        $identity  = $this->seedIdentity($target, 'sub-forbidden');

        $token = self::getContainer()->get('security.csrf.token_manager')
            ->getToken('admin-unlink-identity-' . $identity->getId())->getValue();

        $this->client->loginUser($organizer);
        $this->client->request(
            Request::METHOD_POST,
            '/admin/users/' . $target->getId() . '/identities/' . $identity->getId() . '/unlink',
            ['_token' => $token],
        );
        self::assertResponseStatusCodeSame(403);
    }
}
```

> `security.csrf.token_manager` is the public service id for `CsrfTokenManagerInterface` in the test container. If `->get(...)` reports it non-public in this project, fetch `Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class` instead — use whichever the container exposes.

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Functional/Admin/AdminUserIdentityTest.php`
Expected: FAIL — no unlink form / route `admin_user_identity_unlink` does not exist.

- [ ] **Step 4: Inject the identity repository and pass identities to `edit()`**

In `src/Controller/Admin/UserController.php`:

1. Add imports:

```php
use App\Entity\UserIdentity;
use App\Repository\UserIdentityRepository;
use App\Security\Voter\UserIdentityVoter;
```

2. Add the constructor-promoted dependency (append to the list):

```php
        private readonly UserIdentityRepository $identities,
```

3. In `edit()`, add `identities` to the render array (alongside `styleForm`/`brandLogoSet` from Task 2):

```php
            'identities'   => $target->getIdentities(),
```

- [ ] **Step 5: Add the `unlinkIdentity()` action**

Add to `UserController`:

```php
    #[Route(
        '/admin/users/{id}/identities/{identityId}/unlink',
        name: 'admin_user_identity_unlink',
        requirements: ['id' => '\d+', 'identityId' => '\d+'],
        methods: ['POST'],
    )]
    #[Audited(AuditAction::UserIdentityUnlink, targetParam: 'id', targetType: 'User')]
    public function unlinkIdentity(User $target, int $identityId, Request $request): RedirectResponse
    {
        $identity = $this->identities->find($identityId);
        if (!$identity instanceof UserIdentity || $identity->getUser() !== $target) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(UserIdentityVoter::UNLINK, $identity);

        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('admin-unlink-identity-' . $identityId, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $target->removeIdentity($identity);
        $this->em->remove($identity);
        $this->em->flush();

        $this->audit->set('identity_id', $identityId);
        $this->audit->targetLabel($target->getEmail());
        $this->addFlash('success', 'Identity unlinked.');

        return new RedirectResponse('/admin/users/' . $target->getId() . '/edit');
    }
```

> `$target` resolves from `{id}` via the entity value resolver. `{identityId}` stays a plain `int` so the cross-user guard (`getUser() !== $target`) returns our own 404 instead of the resolver's. CSRF failure throws 403 (non-redirect → no audit row); the cross-user 404 is non-redirect too (→ no audit). Only the success path redirects and logs.

- [ ] **Step 6: Add the linked-identities section to the template**

In `templates/admin/user/form.html.twig`, inside `{% block admin_main %}`, after the styling & brand section from Task 2, add:

```twig
    {% if identities is defined %}
        <section class="mt-8 space-y-3">
            <h2 class="text-lg font-medium">Linked identities</h2>
            {% if identities is empty %}
                <p class="text-sm text-base-content/70">No identities linked.</p>
            {% else %}
                <table class="table">
                    <thead>
                        <tr><th>Provider</th><th>Email</th><th>Linked</th><th></th></tr>
                    </thead>
                    <tbody>
                    {% for identity in identities %}
                        <tr>
                            <td>{{ identity.provider.value|capitalize }}</td>
                            <td>{{ identity.email }}</td>
                            <td>{{ identity.linkedAt|date('Y-m-d H:i') }}</td>
                            <td>
                                <form method="post"
                                      action="{{ path('admin_user_identity_unlink', {id: target_id, identityId: identity.id}) }}">
                                    <input type="hidden" name="_token"
                                           value="{{ csrf_token('admin-unlink-identity-' ~ identity.id) }}">
                                    <button class="btn btn-sm btn-ghost">Unlink</button>
                                </form>
                            </td>
                        </tr>
                    {% endfor %}
                    </tbody>
                </table>
            {% endif %}
        </section>
    {% endif %}
```

> No "link identity" button — linking is actor-bound OAuth (Global Constraints). Guarded on `identities is defined` so the `new`-mode page is unaffected.

- [ ] **Step 7: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Functional/Admin/AdminUserIdentityTest.php`
Expected: PASS (3 tests).

- [ ] **Step 8: Run gates**

Run: `vendor/bin/phpunit tests/Functional/Admin/ tests/Functional/Audit/`
Expected: PASS — `AuditCoverageTest` still green (the new POST route carries `#[Audited]`), no regressions.
Run: `vendor/bin/rector process --dry-run` over `src/Controller/Admin/UserController.php`, `src/Audit/AuditAction.php`, `tests/Functional/Admin/AdminUserIdentityTest.php`; apply what it reports.
Run: `vendor/bin/phpstan analyse` and `vendor/bin/grumphp run`.
Expected: all green, exit 0.

- [ ] **Step 9: Stage**

```bash
git add src/Audit/AuditAction.php \
        src/Controller/Admin/UserController.php \
        templates/admin/user/form.html.twig \
        tests/Functional/Admin/AdminUserIdentityTest.php
```

---

## Self-Review

- **Spec coverage:**
  - Shared profile loader (spec §1) → Task 1. ✓
  - Admin styling + brand render/save/serve (spec §2) → Task 2. ✓
  - Admin identities view + unlink with cross-user 404 (spec §3) → Task 3. ✓
  - Template sections on `/admin/users/{id}/edit` (spec §4) → Tasks 2 & 3. ✓
  - Authorization (`UserVoter::EDIT`/`VIEW`, `UserIdentityVoter::UNLINK`) + audit cases `UserStyleChange`/`UserIdentityUnlink` (spec §5) → Tasks 2 & 3. ✓
  - Password out of scope, no admin identity-link, no lockout guard (Non-goals) → honored; nothing added. ✓
- **Placeholder scan:** every code step has complete code; no TBD/"handle edge cases". ✓
- **Type consistency:** `loadOrCreate(User): OrganizerProfile` used identically in Tasks 1→2; `getBrandLogoFilename(): ?string`, `getIdentities()`, `removeIdentity()`, `UserIdentityRepository::find()`, `UserIdentityVoter::UNLINK`, `UserVoter::EDIT`/`VIEW` all match the read source. Route names (`admin_user_change_style`, `admin_user_brand_logo`, `admin_user_identity_unlink`) consistent between controller, template, and tests. ✓
- **Note carried to execution:** Tasks 2 and 3 both edit `src/Audit/AuditAction.php`, `src/Controller/Admin/UserController.php`, and `templates/admin/user/form.html.twig` in sequence — Task 3 builds on Task 2's version of each. Run them in order; the per-task review diff for Task 3 will include Task 2's additions to those files, so scope Task 3 review attention to the new hunks.

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-07-06-98-admin-account-parity.md`. Two execution options:

1. **Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration.
2. **Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints.

Which approach?
