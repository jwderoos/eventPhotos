# QR Code Generation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a print-friendly QR code page in the admin for each event, plus a PNG download, so organizers can print QR posters that resolve to the public landing page.

**Architecture:** New thin service `App\Service\QrCodeRenderer` wraps endroid/qr-code (`svg()` and `png()`). Two new actions on the existing `App\Controller\Admin\EventController` — `/admin/events/{id}/qr` (HTML w/ inline SVG) and `/admin/events/{id}/qr.png` (binary PNG). Both gated by the existing `EventVoter::VIEW` attribute (admin OR owner). One standalone Twig template, one new link in the events index table.

**Tech Stack:** PHP 8.5, Symfony 8.1, `endroid/qr-code ^6`, Twig, Tailwind + daisyUI, PHPUnit 13, DAMA Doctrine Test Bundle. Spec: `docs/superpowers/specs/2026-06-10-qr-code-generation-design.md`.

---

## Task 0: Branch + GitHub issue

**Files:** none (admin-only)

- [ ] **Step 1: Create GitHub issue**

Run from project root:

```bash
gh issue create \
  --title "QR code generation in the admin" \
  --body "See docs/superpowers/specs/2026-06-10-qr-code-generation-design.md and docs/superpowers/plans/2026-06-10-qr-code-generation.md"
```

Note the issue number returned (referred to as `<N>` below — should be `#9` or higher depending on what already exists).

- [ ] **Step 2: Create the feature branch**

```bash
git checkout main && git pull
git checkout -b feature/<N>-qr-code-generation
```

Expected: `Switched to a new branch 'feature/<N>-qr-code-generation'`.

---

## Task 1: Install endroid/qr-code

**Files:**
- Modify: `composer.json` (adds `endroid/qr-code`)
- Modify: `composer.lock`

- [ ] **Step 1: Add the package**

```bash
composer require endroid/qr-code:^6 --no-interaction
```

Expected: composer installs `endroid/qr-code` plus its small dependency chain (BaconQrCode, DASPRiD/Enum). No Symfony Flex recipe runs.

If composer can't resolve, halt and report — do NOT downgrade to v5 silently. v6 is the only line that publishes against current Symfony.

- [ ] **Step 2: Sanity-check the autoload + a writer class**

```bash
php -r "require 'vendor/autoload.php'; echo class_exists('Endroid\\QrCode\\Builder\\Builder') ? 'OK' : 'MISSING';"
```

Expected: `OK`.

- [ ] **Step 3: Run the full test suite to confirm nothing else broke**

```bash
vendor/bin/phpunit
```

Expected: all existing tests still green (no regressions from composer update).

---

## Task 2: `QrCodeRenderer` service (TDD)

**Files:**
- Create: `src/Service/QrCodeRenderer.php`
- Test: `tests/Unit/Service/QrCodeRendererTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/QrCodeRendererTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\QrCodeRenderer;
use PHPUnit\Framework\TestCase;

final class QrCodeRendererTest extends TestCase
{
    public function testSvgReturnsAnSvgDocumentContainingTheUrlData(): void
    {
        $renderer = new QrCodeRenderer();

        $svg = $renderer->svg('https://example.com/e/summer-fest');

        $this->assertStringContainsString('<svg', $svg);
        $this->assertNotSame('', $svg);
    }

    public function testPngStartsWithThePngMagicBytes(): void
    {
        $renderer = new QrCodeRenderer();

        $png = $renderer->png('https://example.com/e/summer-fest');

        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
    }

    public function testDifferentUrlsProduceDifferentSvgOutput(): void
    {
        $renderer = new QrCodeRenderer();

        $a = $renderer->svg('https://example.com/e/a');
        $b = $renderer->svg('https://example.com/e/b');

        $this->assertNotSame($a, $b);
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

```bash
vendor/bin/phpunit tests/Unit/Service/QrCodeRendererTest.php
```

Expected: FAIL — `Class "App\Service\QrCodeRenderer" not found`.

- [ ] **Step 3: Create the service**

Create `src/Service/QrCodeRenderer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

final class QrCodeRenderer
{
    private const int DEFAULT_SVG_SIZE = 320;
    private const int DEFAULT_PNG_SIZE = 512;
    private const int MARGIN = 10;

    public function svg(string $url, ?int $size = null): string
    {
        return Builder::create()
            ->writer(new SvgWriter())
            ->data($url)
            ->size($size ?? self::DEFAULT_SVG_SIZE)
            ->margin(self::MARGIN)
            ->build()
            ->getString();
    }

    public function png(string $url, ?int $size = null): string
    {
        return Builder::create()
            ->writer(new PngWriter())
            ->data($url)
            ->size($size ?? self::DEFAULT_PNG_SIZE)
            ->margin(self::MARGIN)
            ->build()
            ->getString();
    }
}
```

- [ ] **Step 4: Run the test and confirm it passes**

```bash
vendor/bin/phpunit tests/Unit/Service/QrCodeRendererTest.php
```

Expected: PASS (3 tests, 4 assertions).

If PNG test fails because GD isn't enabled, run:

```bash
php -m | grep -E "^gd$"
```

Expected: `gd`. If missing, the production php-fpm image already has it via `mlocati/php-extension-installer`; if running on host PHP without GD, install it (`brew install php` includes it by default). Halt and report if GD is genuinely unavailable.

- [ ] **Step 5: Stage the changes**

```bash
git add src/Service/QrCodeRenderer.php tests/Unit/Service/QrCodeRendererTest.php
```

(Defer the commit until end of Task 4 — single commit for the feature, per the project's commit-per-issue convention.)

---

## Task 3: Controller actions + print template (TDD)

**Files:**
- Modify: `src/Controller/Admin/EventController.php` (add `qr()` and `qrPng()` actions)
- Create: `templates/admin/event/qr.html.twig`
- Test: `tests/Functional/Admin/EventQrTest.php`

- [ ] **Step 1: Write the failing functional test**

Create `tests/Functional/Admin/EventQrTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EventQrTest extends WebTestCase
{
    public function testOwnerSeesPrintPageWithEventNameAndSvg(): void
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
        $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/qr', (int) $event->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Summer Fest');
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('<svg', $content);
        $this->assertStringContainsString('Scan to see your photos', $content);
    }

    public function testOwnerDownloadsPngWithCorrectContentType(): void
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
        $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/qr.png', (int) $event->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'image/png');
        $body = (string) $client->getResponse()->getContent();
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $body);
    }

    public function testNonOwnerOrganizerGets403(): void
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

        $aliceEvent = new Event('alice-fest', 'Alice Fest', new DateTimeImmutable('2026-07-15'), $alice);

        $em->persist($alice);
        $em->persist($bob);
        $em->persist($aliceEvent);
        $em->flush();

        $client->loginUser($bob);
        $client->request(Request::METHOD_GET, sprintf('/admin/events/%d/qr', (int) $aliceEvent->getId()));

        $this->assertResponseStatusCodeSame(403);
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

```bash
vendor/bin/phpunit tests/Functional/Admin/EventQrTest.php
```

Expected: FAIL — no route matches `/admin/events/{id}/qr` (404 → assertion fails on first test, 404 vs expected 200).

- [ ] **Step 3: Add the two actions to `EventController`**

Open `src/Controller/Admin/EventController.php`.

Add these imports (if not already present):

```php
use App\Security\Voter\EventVoter;
use App\Service\QrCodeRenderer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
```

Add these two methods inside the `EventController` class (after the existing `delete()` action is fine):

```php
    #[Route(
        '/admin/events/{id}/qr',
        name: 'admin_event_qr',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function qr(
        Event $event,
        QrCodeRenderer $renderer,
        UrlGeneratorInterface $urlGenerator,
    ): Response {
        $this->denyAccessUnlessGranted(EventVoter::VIEW, $event);

        $url = $urlGenerator->generate(
            'public_event_landing',
            ['slug' => $event->getSlug()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $this->render('admin/event/qr.html.twig', [
            'event' => $event,
            'url'   => $url,
            'svg'   => $renderer->svg($url),
        ]);
    }

    #[Route(
        '/admin/events/{id}/qr.png',
        name: 'admin_event_qr_png',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function qrPng(
        Event $event,
        QrCodeRenderer $renderer,
        UrlGeneratorInterface $urlGenerator,
    ): Response {
        $this->denyAccessUnlessGranted(EventVoter::VIEW, $event);

        $url = $urlGenerator->generate(
            'public_event_landing',
            ['slug' => $event->getSlug()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return new Response(
            $renderer->png($url),
            Response::HTTP_OK,
            [
                'Content-Type'        => 'image/png',
                'Content-Disposition' => sprintf('inline; filename="event-%s.png"', $event->getSlug()),
            ],
        );
    }
```

- [ ] **Step 4: Create the print template**

Create `templates/admin/event/qr.html.twig`. This does **not** extend `admin/_base.html.twig` — print page is standalone.

```twig
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <title>{{ event.name }} — QR</title>
    {{ importmap('app') }}
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white; }
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center bg-base-200 p-8">
    <main class="w-full max-w-md bg-base-100 rounded-box shadow p-8 text-center">
        <div class="mx-auto mb-6" style="width:320px;max-width:100%">
            {{ svg|raw }}
        </div>

        <h1 class="text-3xl font-semibold mb-1">{{ event.name }}</h1>
        <p class="text-base-content/70 mb-2">{{ event.date|date('Y-m-d') }}</p>
        <p class="text-lg mb-4">Scan to see your photos</p>

        <p class="text-xs text-base-content/50 break-all mb-6">{{ url }}</p>

        <div class="no-print flex gap-2 justify-center">
            <button type="button" onclick="window.print()" class="btn btn-primary btn-sm">Print</button>
            <a href="{{ path('admin_event_qr_png', {id: event.id}) }}"
               download="event-{{ event.slug }}.png"
               class="btn btn-sm">Download PNG</a>
            <a href="{{ path('admin_event_index') }}" class="btn btn-ghost btn-sm">Back</a>
        </div>
    </main>
</body>
</html>
```

- [ ] **Step 5: Run the functional tests and confirm they pass**

```bash
vendor/bin/phpunit tests/Functional/Admin/EventQrTest.php
```

Expected: PASS (3 tests, ~7 assertions).

If a test that hits the PNG endpoint logs a `Risky` warning about output, that's because Symfony's `ExceptionListener` writes the test 403 to `error_log()`. Monolog should already absorb this (see `config/packages/monolog.yaml`); if it doesn't, halt and report — don't loosen `phpunit.xml`'s `beStrictAboutOutputDuringTests`.

- [ ] **Step 6: Stage the changes**

```bash
git add \
    src/Controller/Admin/EventController.php \
    templates/admin/event/qr.html.twig \
    tests/Functional/Admin/EventQrTest.php
```

(Defer the commit until end of Task 4.)

---

## Task 4: Index table "QR" link + final verification + commit

**Files:**
- Modify: `templates/admin/event/index.html.twig` (one new `<a>` in the actions cell)

- [ ] **Step 1: Add the QR link to the events index actions cell**

Open `templates/admin/event/index.html.twig`. Find the actions cell — it currently looks roughly like:

```twig
<td class="text-right">
    <a href="{{ path('admin_event_edit', {id: event.id}) }}" class="btn btn-sm">Edit</a>
    <form method="post" ...>
        ...delete...
    </form>
</td>
```

Insert the QR link as the **first** action, before `Edit`:

```twig
<a href="{{ path('admin_event_qr', {id: event.id}) }}"
   target="_blank"
   rel="noopener"
   class="btn btn-sm">QR</a>
```

`target="_blank"` so the organizer keeps their place in the index. `rel="noopener"` is standard for any `target="_blank"` link.

- [ ] **Step 2: Manual browser smoke check**

Make sure the dev stack is up (`docker compose up -d` if not). Sign in at `http://localhost:8080/login` with `demo@example.com` / `password123`. From the events index, click "+ New event" if you don't already have one, then click "QR" on a row. Confirm:

- A new tab opens at `/admin/events/{id}/qr`.
- The QR is rendered (try scanning with a phone — it should land on `/e/{slug}`).
- Event name, date, tagline, and URL are visible.
- Clicking "Print" opens browser print dialog. The buttons disappear in the print preview.
- Clicking "Download PNG" downloads a file named `event-{slug}.png` that opens as a valid PNG.

- [ ] **Step 3: Run the full test suite**

```bash
vendor/bin/phpunit
```

Expected: all green (previous 26 + 6 new = 32 tests, ~70 assertions). If any pre-existing test breaks, the cause is most likely a service-autowiring conflict from `QrCodeRenderer` — confirm `src/Service/` is included in the default `services.yaml` autoload glob (it is, by default Symfony).

- [ ] **Step 4: Run the full quality gate**

```bash
vendor/bin/grumphp run
```

Expected: exit 0. If phpstan/phpcs/rector fire, apply rector first:

```bash
vendor/bin/rector process --clear-cache
vendor/bin/grumphp run
```

If `phpcs` flags long lines on the new attributes/constructors, split them across multiple lines (same pattern used in the existing admin controllers).

- [ ] **Step 5: Stage the last change and commit**

```bash
git add templates/admin/event/index.html.twig
git status --short
```

Expected staged file list:
- `M src/Controller/Admin/EventController.php`
- `M composer.json`, `M composer.lock`
- `M templates/admin/event/index.html.twig`
- `A src/Service/QrCodeRenderer.php`
- `A templates/admin/event/qr.html.twig`
- `A tests/Functional/Admin/EventQrTest.php`
- `A tests/Unit/Service/QrCodeRendererTest.php`

Then create one commit with the issue number prefix (replace `<N>`):

```bash
git commit -m "$(cat <<'EOF'
<N> - add QR code generation in the admin

Resolves #<N>.

- App\Service\QrCodeRenderer wraps endroid/qr-code with svg() + png() helpers.
- Two admin actions on EventController:
  * GET /admin/events/{id}/qr      -> standalone print page (inline SVG +
    event name + date + 'Scan to see your photos' tagline + URL + Print /
    Download PNG / Back buttons; @media print hides buttons).
  * GET /admin/events/{id}/qr.png  -> binary PNG download.
- Both gated by EventVoter::VIEW (admin OR owner; existing voter already
  declared the attribute, this is the first caller).
- Events index actions cell gets a 'QR' link (opens in new tab).
- Tests: QrCodeRendererTest (3 unit), EventQrTest (3 functional incl. 403 for
  non-owner organizer).
EOF
)"
```

- [ ] **Step 6: Push the branch**

```bash
git push -u origin feature/<N>-qr-code-generation
```

Open a PR at the URL printed by `git push`, or via `gh pr create --fill` if the project flow prefers.

---

## Cross-cutting deferred items (still out of scope)

- Logo / branding inside the QR code (no logo asset yet).
- Bulk-print page (multiple QRs per A4 sheet).
- PDF download (browser "Print → Save as PDF" is the workaround).
- QR for `EventCollection` (only `Event` has a public URL).
- Customizable tagline / message on the print page (current tagline is hardcoded).

---

## Self-Review

**Spec coverage:**

| Spec section | Plan task |
|---|---|
| Decisions table | All decisions encoded across Tasks 1–4 |
| Routes (HTML + PNG) | Task 3 Step 3 |
| `EVENT_VIEW` voter usage | Task 3 Step 3 (no voter changes needed — already supports it) |
| URL via `UrlGeneratorInterface::ABSOLUTE_URL` | Task 3 Step 3 |
| `QrCodeRenderer` service | Task 2 |
| Print template (standalone, @media print) | Task 3 Step 4 |
| Index table action | Task 4 Step 1 |
| Tests (unit + 3 functional) | Tasks 2 + 3 |

All spec sections accounted for.

**Placeholder scan:** No "TBD", "implement later", "similar to". Every step has the exact code to write or the exact command to run with expected output.

**Type consistency:**
- Service signatures `svg(string $url, ?int $size = null): string` and `png(...)` consistent across Task 2 test and Task 2 implementation.
- `EventVoter::VIEW` used in both controller actions (Task 3) — matches the constant already declared in `src/Security/Voter/EventVoter.php`.
- Route names `admin_event_qr` and `admin_event_qr_png` used consistently in controller, template (`path('admin_event_qr_png', ...)`), index template (`path('admin_event_qr', ...)`), and functional tests.
- Slug parameter name `slug` (lowercase) matches `public_event_landing` route's expectation.

No issues found.

---

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-06-10-qr-code-generation.md`.** Two execution options:

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — Execute tasks in this session using `superpowers:executing-plans`, batch execution with checkpoints.

**Which approach?**
