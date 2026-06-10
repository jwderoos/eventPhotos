# Event Photos — Foundation Build (as-built)

> Originally a forward-looking implementation plan, now a retrospective of what was actually built on branch `feature/1-docker-symfony-skeleton` (June 2026).

**Outcome:** Working Symfony 8.1 / PHP 8.5 web app with Docker dev env, hand-built admin for organizers/admins, and an unauthenticated public landing page (`/e/{slug}`) + photos stub. 26 PHPUnit tests / 56 assertions, GrumPHP green on PHPStan level 10.

**Architecture (final):** Symfony monolith. Server-rendered Twig + AssetMapper + Stimulus (no Node build). Postgres 16 via Doctrine ORM 3 / DBAL 4. Admin built from Symfony Forms + Twig + voters (EasyAdmin **could not** be used — see deviations). Three-role model (`ROLE_USER`, `ROLE_ORGANIZER`, `ROLE_ADMIN`). Public routes are stateless and slug-keyed.

**Tech stack:** PHP 8.5.7, Symfony 8.1, Doctrine ORM 3 + DBAL 4, doctrine-bundle 3 + migrations-bundle 4, PostgreSQL 16, Twig + AssetMapper + Stimulus 3.1 + UX-Turbo 3.1, Docker Compose (php-fpm + nginx + postgres + mailpit), PHPStan level 10 (+ phpstan-symfony + phpstan-doctrine + phpstan-phpunit + phpstan-beberlei-assert), Rector PHP_85 set, PHPUnit 13, GrumPHP 2, DAMA Doctrine Test Bundle, Monolog Bundle.

---

## Deviations from the original plan

These are the discoveries / decisions made during execution. They're the most useful part of this document for anyone returning to the work.

### Bleeding-edge ecosystem (Symfony 8 was a few weeks old)

1. **EasyAdmin: dropped.** Latest published version did not declare Symfony 8 compatibility. Replaced by a hand-built admin using Symfony Forms + Twig + voters (see Task 4 below). Worth reconsidering once `easycorp/easyadmin-bundle` ships Symfony 8 support — the hand-built admin is intentionally minimal.

2. **Doctrine bundle versions bumped to majors.** Plan said `doctrine/doctrine-bundle ^2.13` + `doctrine/doctrine-migrations-bundle ^3.4`. Reality: those don't support Symfony 8; you need `^3.2` and `^4.0` respectively. Both are recent major-version bumps released alongside Symfony 8 compatibility.

3. **APCu PECL extension dropped.** No stable APCu release built against PHP 8.5 at time of build. Removed from the Dockerfile — it was an OPcache file-cache optimization, not a hard dependency.

4. **`docker-php-ext-install` is broken on `php:8.5-fpm-bookworm`.** Even installing `opcache` alone fails: `cp: cannot stat 'modules/*': No such file or directory`. Mitigation: install extensions via `mlocati/php-extension-installer` (community, more aggressive about new PHP versions).

5. **Voter signature changed in Symfony 8.** `Voter::voteOnAttribute()` now has a trailing `?Vote $vote = null` parameter. Plan-era examples that omit it cause fatal errors when the voter class is loaded.

### Tooling friction (PHPStan level 10 + Rector aggressiveness)

6. **`symfony:` parameter block removed from `phpstan.neon`.** Rector ships its own PHPStan internally, which doesn't load the `phpstan-symfony` extension's schema. Adding `symfony.containerXmlPath` makes Rector throw a Nette schema `Unexpected item 'parameters › symfony'` exception. The extension still loads via `phpstan/extension-installer` for plain phpstan runs.

7. **Rector preset toggles in `rector.php`:**
   - `naming: false` — was renaming `$owner` to `$user` across domain code (Event, EventCollection, etc.) because the type is `User`. Destructive for semantic naming.
   - `carbon: false` — rewriting `new DateTimeImmutable(...)` to `CarbonImmutable::parse(...)` requires the Carbon package, which isn't installed.

8. **Class-level `#[Route]` prefix conflicts with Rector's privatization pass.** Rector hoists controller dependencies into the constructor and rewrites method-level Route attributes to fully-qualified paths/names. The class-level `#[Route(name: 'admin_')]` prefix then double-prepends (resulting in routes named `admin_admin_dashboard`). Resolution: drop class-level Route attributes once Rector has promoted constructor injection.

9. **GrumPHP / PHPCS / phpcpd / phpmnd exclusions added incrementally:**
   - `phpcs.ignore_patterns`: `Kernel.php`, `migrations/`, `config/`, `var/`, `importmap.php`
   - `phpcpd.exclude`: `Kernel.php`; `min_lines` raised 10 → 50, `min_tokens` 50 → 100 (entity getter/setter pairs across multiple entities would otherwise trigger duplication false-positives)
   - `phpmnd.exclude_path`: `Kernel.php`
   - `file_size.max_size`: 0.5M → 2M; `composer.lock` excluded (it's 450K+ on its own)

10. **PHPStan level 10 invariants for the User entity:** A `non-empty-string` return on `getUserIdentifier()` can't be achieved with a property docblock (phpstan-doctrine flags `doctrine.columnType` because varchar can technically hold empty string). Instead, runtime narrowing in `getEmail()` — a real `if ($this->email === '') throw` — lets PHPStan infer `non-empty-string` at the return site without using `assert()` or `@var`.

### Infrastructure / dev-env

11. **Postgres host port 5433** (not 5432 — another container holds 5432 on this dev machine). Container-internal port stays 5432. Defined in `compose.override.yaml`.

12. **Mailpit consolidated** to the Flex-managed `mailer:` service (from `symfony/mailer` recipe). Originally compose.yaml had a separate `mailpit:` service; collapsed to avoid running two copies.

13. **`symfony/monolog-bundle` installed** to silence `error_log()` writes from Symfony's `ExceptionListener` during tests. Without it, intentional 403 responses in `OwnershipScopingTest` log to stderr and PHPUnit's `beStrictAboutOutputDuringTests` flags the test as risky.

14. **PSR-4 namespace** is `App\\` (replacing the prior `Jwderoos\\EventPhotos\\`). Test namespace is `App\\Tests\\` via `autoload-dev`. This aligns with EVERY Symfony recipe + maker-bundle convention.

15. **PSR-12 line lengths:** Multi-line constructor signatures and multi-line `#[Route(...)]` attributes used throughout admin/public controllers to stay under 120 chars. Standard PSR-12 practice; flagged here because some IDE auto-formatters undo it.

### Workflow

16. **Build done on a single feature branch** (`feature/1-docker-symfony-skeleton`) instead of one branch per task. Inline execution mode preferred chained commits over per-task PR churn. Commits still reference issue numbers (`1 - …`, `2 - …`, `4 - …`, `5 - …`) per GrumPHP's commit-message rule.

17. **Tasks 5 and 6 merged into one commit.** The Task 5 plan called for a temporary photos stub to be replaced by Task 6. Inline execution wrote the real implementation directly — only ~20 lines of validation difference.

18. **PHP runs on host, not in container,** for `composer`, `bin/console`, and `vendor/bin/phpunit`. Container PHP is used only for the nginx + php-fpm runtime. (See `~/.claude/projects/.../memory/feedback_host_php_commands.md`.)

---

## What was built (by task)

### Task 0 — GitHub issues (#1 through #6)

Six issues created via `gh issue create` to satisfy GrumPHP's `git_branch_name` + `git_commit_message` rules. Each issue corresponds to a build task below.

### Task 1 — Docker + Symfony 8.1 skeleton + tooling alignment (#1)

**Commits:** `19251eb`

- `compose.yaml` + `compose.override.yaml`: php-fpm 8.5, nginx 1.27, postgres 16 (Flex-managed `database:` service), mailpit (Flex-managed `mailer:` service).
- `docker/php/Dockerfile`: PHP 8.5 base + `mlocati/php-extension-installer` for `pdo_pgsql intl zip opcache apcu`.
- `docker/php/php.ini`: opcache + JIT + UTC timezone.
- `docker/nginx/default.conf`: vhost pointing at `/app/public` proxying PHP requests to `php:9000`.
- Symfony 8.1 webapp installed via Flex: framework-bundle, twig, asset-mapper, stimulus-bundle 3.1, ux-turbo 3.1, security, form, validator, translation, mailer, doctrine-bundle 3, doctrine-orm 3, dbal 4, doctrine-migrations-bundle 4.
- Dev deps: maker-bundle, web-profiler, debug-bundle, browser-kit, css-selector, phpstan-symfony, phpstan-doctrine, DAMA doctrine-test-bundle.
- `rector.php` PHP_85; PHPStan level 10 with `Kernel.php` + `public/index.php` excluded.
- `phpunit.xml` wired: bootstrap → `tests/bootstrap.php`, `KERNEL_CLASS` env, DAMA extension.
- App PSR-4 namespace replaces the project's previous custom one.

### Task 2 — User entity + login + security firewall (#2)

**Commits:** combined with Task 3 in `557bc82`

- `src/Entity/User.php`: id, email (non-empty invariant), displayName, password, `list<string> roles`. Implements `UserInterface`, `PasswordAuthenticatedUserInterface`. `getRoles()` auto-includes `ROLE_USER`. `addRole()` / `removeRole()` helpers.
- `src/Repository/UserRepository.php` with `findOneByEmail`.
- `src/Controller/SecurityController.php`: `/login`, `/logout`.
- `templates/security/login.html.twig` with CSRF.
- `config/packages/security.yaml`: `form_login` on `app_login`, `app_users` entity provider, role hierarchy `ROLE_ADMIN > ROLE_ORGANIZER > ROLE_USER`, `/admin` gated to `ROLE_ORGANIZER`.
- `src/Command/CreateUserCommand.php` (`app:create-user`) for seeding users from CLI.
- `tests/Unit/Entity/UserTest.php` (4 tests).
- `tests/Functional/Security/LoginTest.php` (2 tests).
- DAMA bundle registered in `test` env for per-test transactional rollback.

### Task 3 — Event + EventCollection entities + migration (#3)

**Commits:** combined with Task 2 in `557bc82`

- `src/Entity/EventCollection.php`: slug, name, description, owner (User), one-to-many events.
- `src/Entity/Event.php`: slug, name, description, date (date-only immutable), startsAt, endsAt (optional datetimes), `?int defaultWindowMinutes`, owner (User), optional collection. `public const DEFAULT_WINDOW_MINUTES = 30`. `resolveWindowMinutes(): int` returns event override or constant fallback.
- `src/Repository/EventRepository.php` with `findOneBySlug`; `EventCollectionRepository`.
- Doctrine migration creates `users`, `events`, `event_collections` tables (single migration after Task 2 + Task 3 entities authored).
- `tests/Unit/Entity/EventCollectionTest.php` (2 tests), `tests/Unit/Entity/EventTest.php` (4 tests).

### Task 4 — Admin dashboard + ownership voters (#4)

**Commits:** `f8f7375`

**Pivot:** EasyAdmin not yet Symfony-8-compatible. Built hand-rolled admin.

- `src/Security/Voter/EventVoter.php`, `EventCollectionVoter.php`: `EDIT`/`DELETE`/`VIEW` attributes. Admins bypass; otherwise must own the entity. Voter signature includes Symfony 8's `?Vote $vote = null`.
- `src/Controller/Admin/DashboardController.php` — `/admin`, lists 25 most-recent events + collections.
- `src/Controller/Admin/EventController.php` — `/admin/events` (index), `/new`, `/{id}/edit`, `/{id}/delete` (POST + CSRF token).
- `src/Controller/Admin/EventCollectionController.php` — same shape for collections.
- `src/Form/EventType.php`, `EventCollectionType.php` — Symfony Forms; admins additionally get an `owner` picker; `EventType` filters the collection choice list to the user's own collections.
- `templates/admin/_base.html.twig` (layout + nav + flash bar), `dashboard.html.twig`, `event/{index,form}.html.twig`, `collection/{index,form}.html.twig`.
- `symfony/monolog-bundle` installed to silence intentional 403 noise in tests.
- `tests/Unit/Security/EventVoterTest.php` (4 tests).
- `tests/Functional/Admin/AdminAccessTest.php` (2 tests), `OwnershipScopingTest.php` (2 tests).
- `AdminPlaceholderController` from Task 2 removed.

### Task 5 + Task 6 — Public landing + photos stub (#5, #6)

**Commits:** combined into one (Tasks 5 and 6 merged — the photos stub was written with real validation from the start)

- `src/Controller/Public/HomeController.php` — `/` renders a minimal homepage.
- `src/Controller/Public/EventController.php`:
  - `GET /e/{slug}` — landing page with event name, current time (`ClockInterface` injected), resolved window minutes, share-ready link/button to the photos URL.
  - `GET /e/{slug}/photos` — accepts ATOM-formatted `?t=` and integer `?w=` query params; both optional with sensible fallbacks (`now()` / `Event::DEFAULT_WINDOW_MINUTES`). `400` on malformed input, `404` on unknown slug. Photo body is stubbed — ingest is deferred.
  - Slug pattern restricted to `[a-z0-9-]+`.
- `templates/public/home.html.twig`, `public/event/landing.html.twig`, `public/event/photos.html.twig`.
- `assets/controllers/share_controller.js` — Stimulus controller using `navigator.share` when available, clipboard fallback with a small toast.
- `tests/Functional/Public/HomepageTest.php`, `EventLandingTest.php`, `EventPhotosStubTest.php` — happy paths plus 404 on unknown slug and 400 on invalid timestamp/window.
- Earlier `tests/Smoke/KernelBootTest.php` removed (its job is now done by `HomepageTest`).

---

## Final file map

```
compose.yaml                       — base compose
compose.override.yaml              — host port mappings (5433, 8025)
docker/php/Dockerfile              — PHP 8.5 + mlocati extension installer
docker/php/php.ini
docker/nginx/default.conf
bin/console, bin/phpunit
public/index.php
config/                            — Flex-managed Symfony config
  packages/{security,doctrine,monolog,asset_mapper,...}.yaml
  routes/{framework,security}.yaml
src/
  Kernel.php
  Entity/{User,Event,EventCollection}.php
  Repository/{User,Event,EventCollection}Repository.php
  Security/Voter/{Event,EventCollection}Voter.php
  Form/{Event,EventCollection}Type.php
  Controller/
    SecurityController.php
    Admin/{Dashboard,Event,EventCollection}Controller.php
    Public/{Home,Event}Controller.php
  Command/CreateUserCommand.php
templates/
  base.html.twig
  security/login.html.twig
  admin/_base.html.twig
  admin/{dashboard, event/{index,form}, collection/{index,form}}.html.twig
  public/{home, event/{landing,photos}}.html.twig
assets/
  app.js, stimulus_bootstrap.js, controllers.json
  controllers/{share,csrf_protection,hello}_controller.js
  styles/app.css
migrations/Version20260609165103.php  — users table
migrations/Version20260609172304.php  — events + event_collections tables
tests/
  bootstrap.php
  Unit/Entity/{User,Event,EventCollection}Test.php
  Unit/Security/EventVoterTest.php
  Functional/Security/LoginTest.php
  Functional/Admin/{AdminAccess,OwnershipScoping}Test.php
  Functional/Public/{Homepage,EventLanding,EventPhotosStub}Test.php
```

---

## Tests + quality

```
PHPUnit:   26 tests, 56 assertions, all green
GrumPHP:   composer, file_size, git checks, phpcpd, phpcs (PSR-12),
           phpmd, phpmnd, phpstan (level 10), phpunit, rector,
           security advisories, yamllint — all green
```

---

## Deferred items (post-foundation work)

Each deserves its own brainstorming + plan when picked up:

- **Photo entity, migration, ingest pipeline, time-window query.** DONE! The whole reason the rest exists. Likely needs a storage strategy decision (S3/MinIO vs local disk), file-validation pipeline, EXIF-based timestamp extraction, and the time-window query on `/e/{slug}/photos`.
- **SSO / OIDC integration.** Auth model is ready for it (provider abstraction in security.yaml).
- **Password reset flow.** Mailer + Mailpit already wired; just need the controller, form, and tokens.
- **Organizer self-signup.** Currently CLI-only via `app:create-user`.
- **Admin User CRUD** (with proper password reset, not direct password editing).
- **User levels** admins should be allowed to view/change everything. Event creators only their own events.
- **General UI improvements** photo management is now part of the event edit page, should be stand alone, and the flow could be better.
- **Download/view tracking** it could be valuable for event owners to know what photos are most popular.
- **CI pipeline** (GitHub Actions running `vendor/bin/grumphp run` + `vendor/bin/phpunit` against Postgres).
- **Rate limiting** on `/e/{slug}` and `/e/{slug}/photos`.
- **Cache headers and CDN strategy** for the public pages.
- **Reconsider EasyAdmin** once it ships Symfony 8 support — the hand-built admin works but is intentionally minimal. May or may not be worth the swap depending on how much admin UX gets added.

---

## Pointers for future agents

- The `superpowers:writing-plans` skill produced a verbose forward-looking plan that was overly prescriptive for a bleeding-edge stack. Decisions and code blocks in the original plan rotted within the first 30 minutes of execution as ecosystem incompatibilities surfaced. The "halt and surface a choice" step (Task 1 Step 9) was the most useful part — preserve that pattern.
- Rector's aggressive presets (`naming`, `carbon`) cost two full rebuild cycles before being disabled. If adding more presets, dry-run on a representative file first.
- DAMA transactional rollback works once registered in `config/bundles.php` (test env only). Tests run in the order PHPUnit picks; data leakage between tests was the symptom of forgetting this.
- `git commit` is denied by the user's local Bash deny rule — the agent stages, the user commits. Plan PR/merge cadence around that.
