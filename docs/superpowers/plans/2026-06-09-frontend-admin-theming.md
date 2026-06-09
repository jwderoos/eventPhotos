# Frontend & Admin Theming Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace ad-hoc CSS with Tailwind v4 + daisyUI v5, applying two distinct themes — `corporate` on `/admin`, `winter` on `/` — and polish the admin layout with a collapsible drawer shell and consistent components.

**Architecture:** A single compiled `app.css` containing both daisyUI themes; the active theme is selected via `data-theme` attribute on `<html>` set by per-area base templates (`templates/admin/_base.html.twig`, new `templates/public/_base.html.twig`). Build runs via `symfonycasts/tailwind-bundle` (no Node, no Vite). A new `tailwind` sidecar service in `compose.yaml` runs the watcher in dev.

**Tech Stack:** PHP 8.5, Symfony 8.1, Twig, AssetMapper, Stimulus, `symfonycasts/tailwind-bundle`, Tailwind CSS v4, daisyUI v5, PHPUnit (functional tests with WebTestCase).

**Spec:** `docs/superpowers/specs/2026-06-09-frontend-admin-theming-design.md`

---

## Important conventions for this plan

- **TDD discipline:** This plan touches mostly templates and CSS, which are not unit-testable. Verification is done by running the existing functional test suite (which uses `assertSelectorTextContains` against rendered HTML) after each visual change to confirm we haven't broken structure. Where assertions couple to CSS classes that are changing, the test is updated as part of the task that changes the template.
- **Commit cadence:** One commit per task. Commit message format: `theming(scope): short summary`.
- **Working directory:** All paths are relative to the repository root `/Users/jderoos/PhpstormProjects/eventFotos`.
- **Running PHP commands:** PHP and composer commands run on the host, not via `docker exec` (per project preference). Functional tests use SQLite-via-DAMA or whatever the existing bootstrap arranges — do not change the test bootstrap.
- **CSS rebuild during plan execution:** Run `php bin/console tailwind:build` once after each `assets/styles/app.css` change to confirm the build succeeds. The compiled file lands at `public/assets/styles/app-<hash>.css` (AssetMapper-managed); do not commit that file (it should be in `.gitignore` already; verify in Task 1).

---

## Task 1: Install the Tailwind bundle

**Files:**
- Modify: `composer.json` (via composer command, not direct edit)
- Modify: `assets/styles/app.css`
- Verify: `.gitignore` excludes `public/assets/`

- [ ] **Step 1: Add the bundle dependency**

Run on host:
```bash
composer require symfonycasts/tailwind-bundle
```

Expected: composer adds the package, the Symfony Flex recipe installs the bundle, registers it in `config/bundles.php`, and creates `config/packages/symfonycasts_tailwind.yaml`. The bundle's post-install hook downloads the Tailwind standalone binary into `var/tailwind/`.

- [ ] **Step 2: Verify the binary downloaded**

Run:
```bash
ls -la var/tailwind/
php bin/console tailwind:build --help
```

Expected: a binary file like `var/tailwind/tailwindcss` and the help command prints usage. If the binary is missing, run `php bin/console tailwind:install` to fetch it.

- [ ] **Step 3: Verify `.gitignore` excludes built assets**

Run:
```bash
grep -E "^/?public/assets|^/?var/tailwind" .gitignore
```

Expected output includes both `/public/assets/` (or `public/assets/`) and `/var/tailwind/`. If either is missing, add it.

- [ ] **Step 4: Replace `assets/styles/app.css` with a Tailwind source**

Overwrite `assets/styles/app.css` with exactly:

```css
@import "tailwindcss";
```

- [ ] **Step 5: Run the build to confirm the toolchain works**

Run:
```bash
php bin/console tailwind:build
```

Expected: build completes, output mentions writing a compiled CSS file. No errors.

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock config/bundles.php config/packages/symfonycasts_tailwind.yaml assets/styles/app.css .gitignore
git commit -m "theming(install): add symfonycasts/tailwind-bundle and Tailwind v4 source"
```

---

## Task 2: Configure daisyUI with two themes

**Files:**
- Modify: `assets/styles/app.css`

- [ ] **Step 1: Add daisyUI to the Tailwind source**

Replace `assets/styles/app.css` contents with:

```css
@import "tailwindcss";

@plugin "daisyui" {
    themes: corporate --default, winter;
}
```

Notes:
- Tailwind v4 uses CSS-side directives — no `tailwind.config.js` is needed.
- `corporate --default` marks corporate as the fallback when no `data-theme` attribute is set; this is harmless because every page sets the attribute, but it gives a sensible default if something forgets.
- daisyUI is loaded as a Tailwind v4 plugin via `@plugin`. It will be fetched automatically by the standalone binary on next build.

- [ ] **Step 2: Run the build**

Run:
```bash
php bin/console tailwind:build
```

Expected: build completes. First run will download daisyUI into the standalone binary's cache; this may take a few seconds.

- [ ] **Step 3: Verify themes are present in the built CSS**

Find the built file (path will include a hash) and grep for theme markers:
```bash
find public/assets -name 'app-*.css' -exec grep -l 'corporate' {} \;
find public/assets -name 'app-*.css' -exec grep -l 'winter' {} \;
```

Expected: both grep calls return the same file path. If either is empty, the daisyUI plugin did not load — re-check the `@plugin` block syntax.

- [ ] **Step 4: Commit**

```bash
git add assets/styles/app.css
git commit -m "theming(install): configure daisyUI with corporate and winter themes"
```

---

## Task 3: Add Tailwind watcher as a docker-compose service

**Files:**
- Modify: `compose.yaml`

- [ ] **Step 1: Add a `tailwind` service to `compose.yaml`**

Open `compose.yaml`. After the `php` service block and before the `nginx` service block, insert this service definition (indent matching the existing services):

```yaml
  tailwind:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    command: php bin/console tailwind:build --watch
    volumes:
      - ./:/app:delegated
    working_dir: /app
    depends_on:
      - php
```

The `tailwind` service reuses the same PHP image (it just needs `php bin/console` to invoke the bundle, which in turn calls the binary downloaded into `var/tailwind/`). It mounts the working tree so source edits trigger rebuilds.

- [ ] **Step 2: Smoke-test the service starts**

Run:
```bash
docker compose up -d tailwind
docker compose logs --tail=30 tailwind
docker compose stop tailwind
```

Expected: log output shows the watcher initialized and is watching for changes. No errors.

- [ ] **Step 3: Commit**

```bash
git add compose.yaml
git commit -m "theming(infra): add tailwind watcher service to compose.yaml"
```

---

## Task 4: Restructure `base.html.twig` for per-area theme attributes

**Files:**
- Modify: `templates/base.html.twig`

- [ ] **Step 1: Replace `templates/base.html.twig` with**

```twig
<!DOCTYPE html>
<html lang="en" {% block html_attributes %}{% endblock %}>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{% block title %}Event Photos{% endblock %}</title>
        <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 128 128%22><text y=%221.2em%22 font-size=%2296%22>⚫️</text><text y=%221.3em%22 x=%220.2em%22 font-size=%2276%22 fill=%22%23fff%22>sf</text></svg>">
        {% block stylesheets %}
            <link rel="stylesheet" href="{{ asset('styles/app.css') }}">
        {% endblock %}

        {% block javascripts %}
            {% block importmap %}{{ importmap('app') }}{% endblock %}
        {% endblock %}

        {% set frankenphpHotReload = app.request.server.get('FRANKENPHP_HOT_RELOAD') %}
        {% if frankenphpHotReload %}
        <meta name="frankenphp-hot-reload:url" content="{{ frankenphpHotReload }}">
        <script src="https://cdn.jsdelivr.net/npm/idiomorph"></script>
        <script src="https://cdn.jsdelivr.net/npm/frankenphp-hot-reload/+esm" type="module"></script>
        {% endif %}
    </head>
    <body class="min-h-screen bg-base-100 text-base-content">
        {% block body %}{% endblock %}
    </body>
</html>
```

Notes:
- New: `html_attributes` block on `<html>`, where each sub-base will inject `data-theme="..."`.
- New: explicit `<link rel="stylesheet" href="{{ asset('styles/app.css') }}">` inside the stylesheets block — required because we have a real CSS file now (the old template didn't import any stylesheet).
- New: `min-h-screen bg-base-100 text-base-content` on body — daisyUI semantic classes that respond to the active theme.
- New: `<meta name="viewport">` for mobile.
- Kept: the FrankenPHP hot-reload conditional (env-gated, harmless if unused).

- [ ] **Step 2: Verify existing tests still pass**

Run:
```bash
vendor/bin/phpunit tests/Functional/
```

Expected: all functional tests pass. None of them assert on stylesheets or the body class, so this should be safe.

- [ ] **Step 3: Commit**

```bash
git add templates/base.html.twig
git commit -m "theming(layout): restructure base.html.twig with html_attributes block and stylesheet import"
```

---

## Task 5: Create the public base template and switch public pages to extend it

**Files:**
- Create: `templates/public/_base.html.twig`
- Modify: `templates/public/home.html.twig`
- Modify: `templates/public/event/landing.html.twig`
- Modify: `templates/public/event/photos.html.twig`

- [ ] **Step 1: Create `templates/public/_base.html.twig`**

Contents:

```twig
{% extends 'base.html.twig' %}

{% block html_attributes %}data-theme="winter"{% endblock %}

{% block body %}
    <div class="flex min-h-screen flex-col">
        <header class="border-b border-base-300 bg-base-100">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-4">
                <a href="{{ path('public_home') }}" class="text-lg font-semibold tracking-tight">
                    Event Photos
                </a>
            </div>
        </header>

        <main class="flex-1">
            <div class="mx-auto max-w-5xl px-4 py-10">
                {% block public_main %}{% endblock %}
            </div>
        </main>

        <footer class="border-t border-base-300 bg-base-200">
            <div class="mx-auto max-w-5xl px-4 py-6 text-sm text-base-content/60">
                © {{ "now"|date("Y") }} Event Photos
            </div>
        </footer>
    </div>
{% endblock %}
```

- [ ] **Step 2: Update `templates/public/home.html.twig`**

Replace contents with:

```twig
{% extends 'public/_base.html.twig' %}

{% block title %}Event Photos{% endblock %}

{% block public_main %}
    <section class="prose max-w-none">
        <h1>Event Photos</h1>
        <p>Scan a QR code at an event to view your photos.</p>
    </section>
{% endblock %}
```

- [ ] **Step 3: Update `templates/public/event/landing.html.twig`**

Replace contents with:

```twig
{% extends 'public/_base.html.twig' %}

{% block title %}{{ event.name }}{% endblock %}

{% block public_main %}
    <article {{ stimulus_controller('share') }} class="card bg-base-100 shadow-sm">
        <div class="card-body gap-4">
            <h1 class="card-title text-2xl">{{ event.name }}</h1>

            {% if event.description %}
                <p class="text-base-content/80">{{ event.description }}</p>
            {% endif %}

            <p class="text-sm text-base-content/70">
                Current time:
                <time datetime="{{ now|date('c') }}">{{ now|date('H:i') }}</time>
                <br>
                Window: ±{{ windowMinutes }} minutes
            </p>

            <div class="card-actions justify-end">
                <button
                    type="button"
                    class="btn btn-ghost"
                    data-action="click->share#share"
                    data-share-url-value="{{ url('public_event_photos', {slug: event.slug, t: now|date('c'), w: windowMinutes}) }}"
                    data-share-title-value="{{ event.name }} — Photos"
                    data-share-text-value="My photos from {{ event.name }}"
                >
                    Share
                </button>

                <a
                    href="{{ photosUrl }}"
                    class="btn btn-primary"
                    data-share-url-value="{{ url('public_event_photos', {slug: event.slug, t: now|date('c'), w: windowMinutes}) }}"
                    data-share-title-value="{{ event.name }} — Photos"
                    data-share-text-value="My photos from {{ event.name }}"
                >
                    Show my photos
                </a>
            </div>
        </div>
    </article>
{% endblock %}
```

Notes:
- `stimulus_controller('share')` is preserved on the wrapping element so the existing share controller still binds.
- The "Show my photos" anchor keeps `data-share-*` attributes — the existing test (`EventLandingTest`) asserts `a[href*="/e/summer-fest/photos?t="]` exists, which this still satisfies.
- The share button keeps its `data-action` — `EventLandingTest` asserts `button[data-action*="share#share"]` exists.

- [ ] **Step 4: Update `templates/public/event/photos.html.twig`**

Replace contents with:

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
            </p>
        </header>

        <div class="rounded-box border border-base-300 bg-base-200 p-10 text-center">
            <p class="text-base-content/70">
                <em>Photo ingest is not implemented yet.</em><br>
                Your photos will appear here once added.
            </p>
        </div>

        <p>
            <a href="{{ path('public_event_landing', {slug: event.slug}) }}" class="btn btn-ghost btn-sm">
                ← Back to event
            </a>
        </p>
    </section>
{% endblock %}
```

Notes:
- `data-testid="timestamp"` and `data-testid="window"` are preserved — `EventPhotosStubTest` selects on them.

- [ ] **Step 5: Run the public functional tests**

Run:
```bash
vendor/bin/phpunit tests/Functional/Public/
```

Expected: all pass. The tests assert on `h1` text and on `data-testid` selectors and on link `href` patterns; none of those have changed.

- [ ] **Step 6: Commit**

```bash
git add templates/public/_base.html.twig templates/public/home.html.twig templates/public/event/landing.html.twig templates/public/event/photos.html.twig
git commit -m "theming(public): introduce public _base with winter theme and restyle public pages"
```

---

## Task 6: Rewrite `admin/_base.html.twig` with the drawer shell

**Files:**
- Modify: `templates/admin/_base.html.twig`

- [ ] **Step 1: Replace contents with the drawer shell**

```twig
{% extends 'base.html.twig' %}

{% block html_attributes %}data-theme="corporate"{% endblock %}

{% block body %}
    {% block admin_shell %}
        <div class="drawer lg:drawer-open">
            <input id="admin-drawer" type="checkbox" class="drawer-toggle">

            <div class="drawer-content flex min-h-screen flex-col bg-base-200">
                {# Top bar #}
                <div class="navbar border-b border-base-300 bg-base-100">
                    <div class="flex-none lg:hidden">
                        <label for="admin-drawer" aria-label="Open sidebar" class="btn btn-square btn-ghost">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                            </svg>
                        </label>
                    </div>

                    <div class="flex-1">
                        {% block admin_breadcrumb %}
                            <span class="text-sm text-base-content/70">Admin</span>
                        {% endblock %}
                    </div>

                    {% if app.user %}
                        <div class="flex-none">
                            <div class="dropdown dropdown-end">
                                <label tabindex="0" class="btn btn-ghost">
                                    {{ app.user.displayName }}
                                </label>
                                <ul tabindex="0" class="dropdown-content menu rounded-box z-[1] w-48 bg-base-100 p-2 shadow">
                                    <li><a href="{{ path('app_logout') }}">Sign out</a></li>
                                </ul>
                            </div>
                        </div>
                    {% endif %}
                </div>

                {# Flashes #}
                {% set flashMap = {success: 'alert-success', error: 'alert-error', danger: 'alert-error', warning: 'alert-warning'} %}
                {% for label, messages in app.flashes %}
                    {% for message in messages %}
                        <div class="mx-auto mt-4 w-full max-w-7xl px-4" data-testid="flash-{{ label }}">
                            <div class="alert {{ flashMap[label]|default('alert-info') }}" role="status">
                                <span>{{ message }}</span>
                            </div>
                        </div>
                    {% endfor %}
                {% endfor %}

                {# Page content #}
                <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-8">
                    {% block admin_main %}{% endblock %}
                </main>

                {# Sticky action bar — rendered only when defined by the page #}
                {% if block('admin_actions') is defined %}
                    <div class="sticky bottom-0 border-t border-base-300 bg-base-100/95 backdrop-blur">
                        <div class="mx-auto flex max-w-7xl items-center justify-end gap-2 px-4 py-3">
                            {{ block('admin_actions') }}
                        </div>
                    </div>
                {% endif %}
            </div>

            <div class="drawer-side">
                <label for="admin-drawer" aria-label="close sidebar" class="drawer-overlay"></label>
                <aside class="flex min-h-full w-64 flex-col bg-base-100 border-r border-base-300">
                    <div class="px-4 py-5">
                        <p class="text-sm font-semibold uppercase tracking-wider text-base-content/60">Event Photos</p>
                        <p class="text-lg font-semibold">Admin</p>
                    </div>

                    {% set route = app.request.attributes.get('_route') %}
                    <ul class="menu menu-md px-2">
                        <li>
                            <a href="{{ path('admin_dashboard') }}"
                               class="{{ route == 'admin_dashboard' ? 'active' : '' }}">
                                Dashboard
                            </a>
                        </li>
                        <li>
                            <a href="{{ path('admin_event_index') }}"
                               class="{{ route starts with 'admin_event_' ? 'active' : '' }}">
                                Events
                            </a>
                        </li>
                        <li>
                            <a href="{{ path('admin_collection_index') }}"
                               class="{{ route starts with 'admin_collection_' ? 'active' : '' }}">
                                Collections
                            </a>
                        </li>
                    </ul>
                </aside>
            </div>
        </div>
    {% endblock %}
{% endblock %}
```

Notes on the structure:
- `admin_shell` is the entire visual shell (drawer + content + sidebar). The login page (Task 7) overrides this block to render a standalone card without the drawer.
- `admin_breadcrumb`, `admin_main`, `admin_actions` are all overridable per page.
- `block('admin_actions') is defined` works for blocks declared but possibly empty in a child template — a page that wants the sticky bar declares the block with content; pages that don't, the bar doesn't render.
- The sidebar `menu` uses route-prefix matching for the active state. `admin_event_new`, `admin_event_edit`, `admin_event_delete` all start with `admin_event_` and will all keep "Events" highlighted.

- [ ] **Step 2: Run admin tests**

Run:
```bash
vendor/bin/phpunit tests/Functional/Admin/ tests/Functional/Security/
```

Expected: all pass. `AdminAccessTest::assertSelectorTextContains('h1', 'Dashboard')` passes because the dashboard template still renders an `<h1>` (we haven't restyled it yet — that's Task 8). The existing `LoginTest` still passes because the old login template (still in place) renders inside the new admin shell — its `.error` div remains unchanged.

- [ ] **Step 3: Commit**

```bash
git add templates/admin/_base.html.twig
git commit -m "theming(admin): drawer-shell layout with corporate theme, breadcrumbs, flashes, sticky action slot"
```

---

## Task 7: Restyle the security login page

**Files:**
- Modify: `templates/security/login.html.twig`
- Modify: `tests/Functional/Security/LoginTest.php`

This task lands after Task 6 (admin base) so that the new login template's override of `admin_shell` resolves immediately. The test update and template change ship in the same commit so the suite stays green.

- [ ] **Step 1: Update the test assertion to a `data-testid` selector**

Open `tests/Functional/Security/LoginTest.php` and locate the assertion that targets `.error`:

```php
self::assertSelectorTextContains('.error', 'Invalid credentials');
```

Replace with:

```php
self::assertSelectorTextContains('[data-testid="login-error"]', 'Invalid credentials');
```

- [ ] **Step 2: Replace `templates/security/login.html.twig` with**

```twig
{% extends 'admin/_base.html.twig' %}

{% block title %}Sign in{% endblock %}

{% block admin_shell %}
    <div class="flex min-h-screen items-center justify-center bg-base-200 px-4">
        <div class="card w-full max-w-sm bg-base-100 shadow-md">
            <div class="card-body gap-4">
                <h1 class="card-title justify-center text-xl">Sign in</h1>

                {% if error %}
                    <div data-testid="login-error" class="alert alert-error" role="alert">
                        <span>{{ error.messageKey|trans(error.messageData, 'security') }}</span>
                    </div>
                {% endif %}

                <form method="post" action="{{ path('app_login') }}" class="space-y-3">
                    <input type="hidden" name="_csrf_token" value="{{ csrf_token('authenticate') }}">

                    <label class="form-control w-full">
                        <span class="label-text">Email</span>
                        <input type="email" name="_username" value="{{ last_username }}" required autofocus
                               class="input input-bordered w-full">
                    </label>

                    <label class="form-control w-full">
                        <span class="label-text">Password</span>
                        <input type="password" name="_password" required
                               class="input input-bordered w-full">
                    </label>

                    <button type="submit" class="btn btn-primary w-full">Sign in</button>
                </form>
            </div>
        </div>
    </div>
{% endblock %}
```

Notes:
- This template extends `admin/_base.html.twig` so it inherits `data-theme="corporate"`.
- It overrides the `admin_shell` block (not `admin_main`) so the login page replaces the entire shell — no drawer, no top bar — while still inheriting the theme.

- [ ] **Step 3: Run the login test**

Run:
```bash
vendor/bin/phpunit tests/Functional/Security/LoginTest.php
```

Expected: passes. The template now exposes `[data-testid="login-error"]` for the failure-case assertion.

- [ ] **Step 4: Commit**

```bash
git add templates/security/login.html.twig tests/Functional/Security/LoginTest.php
git commit -m "theming(security): restyle login page with daisyUI card and data-testid for errors"
```

---

## Task 8: Restyle the admin dashboard

**Files:**
- Modify: `templates/admin/dashboard.html.twig`

- [ ] **Step 1: Replace contents with**

```twig
{% extends 'admin/_base.html.twig' %}

{% block title %}Admin — Dashboard{% endblock %}

{% block admin_breadcrumb %}
    <div class="breadcrumbs text-sm">
        <ul>
            <li>Admin</li>
            <li>Dashboard</li>
        </ul>
    </div>
{% endblock %}

{% block admin_main %}
    <header class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Dashboard</h1>
    </header>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <div class="flex items-center justify-between">
                    <h2 class="card-title">Recent events</h2>
                    <a href="{{ path('admin_event_new') }}" class="btn btn-primary btn-sm">+ New</a>
                </div>

                {% if events %}
                    <ul class="divide-y divide-base-200">
                        {% for event in events %}
                            <li class="py-2">
                                <a href="{{ path('admin_event_edit', {id: event.id}) }}" class="link link-hover font-medium">
                                    {{ event.name }}
                                </a>
                                <span class="text-sm text-base-content/70">
                                    — {{ event.date|date('Y-m-d') }} (slug: <code class="text-xs">{{ event.slug }}</code>)
                                </span>
                            </li>
                        {% endfor %}
                    </ul>
                {% else %}
                    <p class="text-base-content/60">No events yet.</p>
                {% endif %}
            </div>
        </section>

        <section class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <div class="flex items-center justify-between">
                    <h2 class="card-title">Collections</h2>
                    <a href="{{ path('admin_collection_new') }}" class="btn btn-primary btn-sm">+ New</a>
                </div>

                {% if collections %}
                    <ul class="divide-y divide-base-200">
                        {% for collection in collections %}
                            <li class="py-2">
                                <a href="{{ path('admin_collection_edit', {id: collection.id}) }}" class="link link-hover font-medium">
                                    {{ collection.name }}
                                </a>
                            </li>
                        {% endfor %}
                    </ul>
                {% else %}
                    <p class="text-base-content/60">No collections yet.</p>
                {% endif %}
            </div>
        </section>
    </div>
{% endblock %}
```

- [ ] **Step 2: Run admin access test**

Run:
```bash
vendor/bin/phpunit tests/Functional/Admin/AdminAccessTest.php
```

Expected: passes. The test asserts `h1` contains "Dashboard", which still holds.

- [ ] **Step 3: Commit**

```bash
git add templates/admin/dashboard.html.twig
git commit -m "theming(admin): restyle dashboard with daisyUI cards"
```

---

## Task 9: Restyle the admin event index

**Files:**
- Modify: `templates/admin/event/index.html.twig`

- [ ] **Step 1: Replace contents with**

```twig
{% extends 'admin/_base.html.twig' %}

{% block title %}Admin — Events{% endblock %}

{% block admin_breadcrumb %}
    <div class="breadcrumbs text-sm">
        <ul>
            <li>Admin</li>
            <li>Events</li>
        </ul>
    </div>
{% endblock %}

{% block admin_main %}
    <header class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Events</h1>
        <a href="{{ path('admin_event_new') }}" class="btn btn-primary btn-sm">+ New event</a>
    </header>

    {% if events %}
        <div class="overflow-x-auto rounded-box border border-base-300 bg-base-100">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>Slug</th>
                        <th>Name</th>
                        <th>Date</th>
                        <th>Window</th>
                        <th>Owner</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    {% for event in events %}
                        <tr>
                            <td><code class="text-xs">{{ event.slug }}</code></td>
                            <td class="font-medium">{{ event.name }}</td>
                            <td>{{ event.date|date('Y-m-d') }}</td>
                            <td>{{ event.resolveWindowMinutes }} min</td>
                            <td class="text-sm text-base-content/70">{{ event.owner.email }}</td>
                            <td class="text-right">
                                <div class="inline-flex items-center gap-1">
                                    <a href="{{ path('admin_event_edit', {id: event.id}) }}"
                                       class="btn btn-ghost btn-xs">Edit</a>
                                    <form method="post"
                                          action="{{ path('admin_event_delete', {id: event.id}) }}"
                                          onsubmit="return confirm('Delete this event?')"
                                          class="inline">
                                        <input type="hidden" name="_token" value="{{ csrf_token('delete_event_' ~ event.id) }}">
                                        <button type="submit" class="btn btn-ghost btn-xs text-error">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    {% endfor %}
                </tbody>
            </table>
        </div>
    {% else %}
        <div class="rounded-box border border-dashed border-base-300 bg-base-100 p-10 text-center">
            <p class="text-base-content/70">No events yet.</p>
            <a href="{{ path('admin_event_new') }}" class="btn btn-primary btn-sm mt-4">Create your first event</a>
        </div>
    {% endif %}
{% endblock %}
```

- [ ] **Step 2: Run admin access and ownership tests**

Run:
```bash
vendor/bin/phpunit tests/Functional/Admin/
```

Expected: all pass. Existing tests do not assert on specific table structure beyond text content.

- [ ] **Step 3: Commit**

```bash
git add templates/admin/event/index.html.twig
git commit -m "theming(admin): restyle event index with zebra table and empty state"
```

---

## Task 10: Restyle the admin event form

**Files:**
- Modify: `templates/admin/event/form.html.twig`

- [ ] **Step 1: Replace contents with**

```twig
{% extends 'admin/_base.html.twig' %}

{% block title %}Admin — {{ mode == 'new' ? 'New event' : 'Edit ' ~ event.name }}{% endblock %}

{% block admin_breadcrumb %}
    <div class="breadcrumbs text-sm">
        <ul>
            <li>Admin</li>
            <li><a href="{{ path('admin_event_index') }}" class="link link-hover">Events</a></li>
            <li>{{ mode == 'new' ? 'New' : 'Edit' }}</li>
        </ul>
    </div>
{% endblock %}

{% block admin_main %}
    <header class="mb-6">
        <h1 class="text-2xl font-semibold">
            {{ mode == 'new' ? 'New event' : 'Edit ' ~ event.name }}
        </h1>
    </header>

    {{ form_start(form, {attr: {id: 'event-form', class: 'card bg-base-100 shadow-sm'}}) }}
        <div class="card-body grid gap-4 lg:grid-cols-2">
            {{ form_widget(form) }}
        </div>
    {{ form_end(form, {render_rest: false}) }}
{% endblock %}

{% block admin_actions %}
    <a href="{{ path('admin_event_index') }}" class="btn btn-ghost">Cancel</a>
    <button type="submit" form="event-form" class="btn btn-primary">
        {{ mode == 'new' ? 'Create' : 'Save' }}
    </button>
{% endblock %}

{% block stylesheets %}
    {{ parent() }}
    <style>
        /* daisyUI-like styling for Symfony-generated form widgets without a custom form theme.
           Uses raw CSS (not Tailwind @apply, which is build-time only) so it works inside a
           runtime <style> block. daisyUI theme colors are exposed as CSS variables: --bc
           (base-content), --b1 (base-100), --er (error). */
        #event-form > div { display: grid; gap: 1rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        #event-form > div > div { display: flex; flex-direction: column; gap: 0.375rem; }
        #event-form label { font-size: 0.875rem; font-weight: 500; color: oklch(var(--bc)); }
        #event-form input[type="text"],
        #event-form input[type="email"],
        #event-form input[type="number"],
        #event-form input[type="date"],
        #event-form input[type="datetime-local"],
        #event-form textarea,
        #event-form select {
            width: 100%;
            border: 1px solid oklch(var(--bc) / 0.2);
            border-radius: 0.5rem;
            padding: 0.5rem 0.75rem;
            background-color: oklch(var(--b1));
            color: oklch(var(--bc));
            font-size: 0.875rem;
        }
        #event-form textarea { min-height: 6rem; }
        #event-form .form-help, #event-form small { font-size: 0.75rem; color: oklch(var(--bc) / 0.6); }
        #event-form ul { color: oklch(var(--er)); font-size: 0.75rem; margin-top: 0.25rem; padding-left: 1rem; }
        @media (max-width: 1024px) {
            #event-form > div { grid-template-columns: 1fr; }
        }
    </style>
{% endblock %}
```

Notes:
- Symfony's default form theme outputs unstyled `<input>`, `<select>`, etc. Rather than build a full Twig form theme right now, the page scopes raw CSS to `#event-form`.
- Using daisyUI's CSS variables (`--bc`, `--b1`, `--er`) keeps the form responsive to the active theme.
- Tailwind's `@apply` is intentionally NOT used — it is a build-time directive, not runtime CSS, so it would not work inside an inline `<style>` block.
- This is tactical. If form styling gets richer later, build a proper Symfony form theme (out of scope here).

- [ ] **Step 2: Verify by visiting `/admin/events/new` in a browser**

Run:
```bash
docker compose up -d
```

Then open `http://localhost:8080/admin/events/new` after logging in.

Expected: form fields render with daisyUI-style borders, two-column grid on desktop, single column on mobile. Sticky bar at the bottom with Cancel + Create.

- [ ] **Step 3: Run admin tests**

Run:
```bash
vendor/bin/phpunit tests/Functional/Admin/
```

Expected: passes.

- [ ] **Step 4: Commit**

```bash
git add templates/admin/event/form.html.twig
git commit -m "theming(admin): restyle event form with two-column grid and sticky action bar"
```

---

## Task 11: Restyle the admin collection index

**Files:**
- Modify: `templates/admin/collection/index.html.twig`

- [ ] **Step 1: Replace contents with**

```twig
{% extends 'admin/_base.html.twig' %}

{% block title %}Admin — Collections{% endblock %}

{% block admin_breadcrumb %}
    <div class="breadcrumbs text-sm">
        <ul>
            <li>Admin</li>
            <li>Collections</li>
        </ul>
    </div>
{% endblock %}

{% block admin_main %}
    <header class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Collections</h1>
        <a href="{{ path('admin_collection_new') }}" class="btn btn-primary btn-sm">+ New collection</a>
    </header>

    {% if collections %}
        <div class="overflow-x-auto rounded-box border border-base-300 bg-base-100">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>Slug</th>
                        <th>Name</th>
                        <th>Owner</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    {% for collection in collections %}
                        <tr>
                            <td><code class="text-xs">{{ collection.slug }}</code></td>
                            <td class="font-medium">{{ collection.name }}</td>
                            <td class="text-sm text-base-content/70">{{ collection.owner.email }}</td>
                            <td class="text-right">
                                <div class="inline-flex items-center gap-1">
                                    <a href="{{ path('admin_collection_edit', {id: collection.id}) }}"
                                       class="btn btn-ghost btn-xs">Edit</a>
                                    <form method="post"
                                          action="{{ path('admin_collection_delete', {id: collection.id}) }}"
                                          onsubmit="return confirm('Delete this collection?')"
                                          class="inline">
                                        <input type="hidden" name="_token" value="{{ csrf_token('delete_collection_' ~ collection.id) }}">
                                        <button type="submit" class="btn btn-ghost btn-xs text-error">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    {% endfor %}
                </tbody>
            </table>
        </div>
    {% else %}
        <div class="rounded-box border border-dashed border-base-300 bg-base-100 p-10 text-center">
            <p class="text-base-content/70">No collections yet.</p>
            <a href="{{ path('admin_collection_new') }}" class="btn btn-primary btn-sm mt-4">Create your first collection</a>
        </div>
    {% endif %}
{% endblock %}
```

- [ ] **Step 2: Run admin tests**

Run:
```bash
vendor/bin/phpunit tests/Functional/Admin/
```

Expected: passes.

- [ ] **Step 3: Commit**

```bash
git add templates/admin/collection/index.html.twig
git commit -m "theming(admin): restyle collection index with zebra table and empty state"
```

---

## Task 12: Restyle the admin collection form

**Files:**
- Modify: `templates/admin/collection/form.html.twig`

- [ ] **Step 1: Replace contents with**

```twig
{% extends 'admin/_base.html.twig' %}

{% block title %}Admin — {{ mode == 'new' ? 'New collection' : 'Edit ' ~ collection.name }}{% endblock %}

{% block admin_breadcrumb %}
    <div class="breadcrumbs text-sm">
        <ul>
            <li>Admin</li>
            <li><a href="{{ path('admin_collection_index') }}" class="link link-hover">Collections</a></li>
            <li>{{ mode == 'new' ? 'New' : 'Edit' }}</li>
        </ul>
    </div>
{% endblock %}

{% block admin_main %}
    <header class="mb-6">
        <h1 class="text-2xl font-semibold">
            {{ mode == 'new' ? 'New collection' : 'Edit ' ~ collection.name }}
        </h1>
    </header>

    {{ form_start(form, {attr: {id: 'collection-form', class: 'card bg-base-100 shadow-sm'}}) }}
        <div class="card-body grid gap-4 lg:grid-cols-2">
            {{ form_widget(form) }}
        </div>
    {{ form_end(form, {render_rest: false}) }}
{% endblock %}

{% block admin_actions %}
    <a href="{{ path('admin_collection_index') }}" class="btn btn-ghost">Cancel</a>
    <button type="submit" form="collection-form" class="btn btn-primary">
        {{ mode == 'new' ? 'Create' : 'Save' }}
    </button>
{% endblock %}

{% block stylesheets %}
    {{ parent() }}
    <style>
        /* Apply daisyUI-like styling to Symfony-generated form widgets without a custom form theme. */
        #collection-form > div { display: grid; gap: 1rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        #collection-form > div > div { display: flex; flex-direction: column; gap: 0.375rem; }
        #collection-form label { font-size: 0.875rem; font-weight: 500; color: oklch(var(--bc)); }
        #collection-form input[type="text"],
        #collection-form input[type="email"],
        #collection-form textarea,
        #collection-form select {
            width: 100%;
            border: 1px solid oklch(var(--bc) / 0.2);
            border-radius: 0.5rem;
            padding: 0.5rem 0.75rem;
            background-color: oklch(var(--b1));
            color: oklch(var(--bc));
            font-size: 0.875rem;
        }
        #collection-form textarea { min-height: 6rem; }
        #collection-form .form-help, #collection-form small { font-size: 0.75rem; color: oklch(var(--bc) / 0.6); }
        #collection-form ul { color: oklch(var(--er)); font-size: 0.75rem; margin-top: 0.25rem; padding-left: 1rem; }
        @media (max-width: 1024px) {
            #collection-form > div { grid-template-columns: 1fr; }
        }
    </style>
{% endblock %}
```

- [ ] **Step 2: Run admin tests**

Run:
```bash
vendor/bin/phpunit tests/Functional/Admin/
```

Expected: passes.

- [ ] **Step 3: Commit**

```bash
git add templates/admin/collection/form.html.twig
git commit -m "theming(admin): restyle collection form with two-column grid and sticky action bar"
```

---

## Task 13: Full verification and browser smoke

**Files:**
- None modified. Verification only.

- [ ] **Step 1: Run the entire test suite**

Run:
```bash
vendor/bin/phpunit
```

Expected: all tests pass. If any fail, investigate and fix as part of this task before moving on.

- [ ] **Step 2: Rebuild CSS once more from clean state**

Run:
```bash
rm -rf public/assets
php bin/console tailwind:build
ls public/assets/styles/
```

Expected: a single `app-<hash>.css` file present (and any other AssetMapper-managed entries).

- [ ] **Step 3: Boot the stack and smoke-test every route**

Run:
```bash
docker compose up -d
```

Manually walk through these URLs in a browser, logging in where required:

| URL | Expected |
| --- | --- |
| `http://localhost:8080/` | Public theme (`winter`), header with wordmark, "Event Photos" h1, "Scan a QR code…" body |
| `http://localhost:8080/e/<existing-slug>` | Public theme, event card with Share and Show-my-photos buttons |
| `http://localhost:8080/e/<existing-slug>/photos` | Public theme, timestamp/window line, empty-state photo block |
| `http://localhost:8080/login` | Admin theme (`corporate`), centered card login form |
| `http://localhost:8080/admin/` | Admin theme, drawer sidebar with active "Dashboard", two dashboard cards |
| `http://localhost:8080/admin/events` | Admin theme, zebra table OR empty state, "Events" active in sidebar |
| `http://localhost:8080/admin/events/new` | Admin theme, two-column form (single column on narrow viewport), sticky bottom bar with Cancel + Create |
| `http://localhost:8080/admin/events/<id>/edit` | Same as new but populated; button reads "Save" |
| `http://localhost:8080/admin/collections` | Admin theme, zebra table OR empty state |
| `http://localhost:8080/admin/collections/new` | Admin theme, form, sticky bar |

For each: confirm no console errors, no flash of unstyled content, and that the sidebar collapses to a hamburger on mobile widths (resize the browser to <1024px).

- [ ] **Step 4: Verify the watcher service end-to-end**

With `docker compose up` running, edit `assets/styles/app.css` (add a stray comment), then check:

```bash
docker compose logs --tail=20 tailwind
```

Expected: log shows a rebuild happening within ~1 second. Undo the edit.

- [ ] **Step 5: Final commit if any small fixes were applied**

If Step 3 surfaced any visual or behavioral defect that required a small template fix, commit it with:

```bash
git add <files>
git commit -m "theming(polish): <what was fixed>"
```

If nothing needed fixing, skip this step.

---

## Self-review notes (for the executing agent)

The following are the design constraints that the plan tasks above must satisfy together. Treat this section as your acceptance checklist.

- **Spec coverage:** Every file listed in the spec's "Template migration map" is touched by exactly one task. The Tailwind bundle install is Task 1. daisyUI config is Task 2. Compose watcher is Task 3. `base.html.twig` is Task 4. `public/_base.html.twig` and the three public pages are Task 5. `admin/_base.html.twig` is Task 6. `security/login.html.twig` (with its test update) is Task 7. `admin/dashboard.html.twig` is Task 8. Event index/form are Tasks 9 and 10. Collection index/form are Tasks 11 and 12. Verification is Task 13.
- **Theme coverage:** Both `data-theme="corporate"` (admin) and `data-theme="winter"` (public) are set by their respective `_base` templates.
- **`btn-primary` collision:** Resolved by replacing every old occurrence with daisyUI's `btn btn-primary` (semantically the same intent, different class composition).
- **Sticky action bar:** Implemented in `admin/_base.html.twig` Task 6 via `block('admin_actions') is defined` gating, and exercised by Tasks 10 and 12 (event form and collection form).
- **Login uses admin theme:** Achieved by `templates/security/login.html.twig` extending `admin/_base.html.twig` and overriding the `admin_shell` block.
- **One CSS bundle:** Single `assets/styles/app.css` source, single compiled `app-<hash>.css` output. Both themes live in it.
- **No Node:** Verified — `symfonycasts/tailwind-bundle` is the only tool; the standalone binary is invoked via `php bin/console tailwind:build`.
- **No new tests for visual styling:** Only the existing functional tests are exercised. One existing test is updated (Task 7, `LoginTest`) to decouple from a CSS class that no longer exists.
