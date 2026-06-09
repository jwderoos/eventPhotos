# Event Photos — Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver a working Symfony 8.1 / PHP 8.5 web app with Docker dev env, authenticated EasyAdmin for organizers/admins, and an unauthenticated public landing page (`/e/{slug}`) with share UX. Photo ingest is explicitly deferred.

**Architecture:** Symfony monolith. Server-rendered Twig + AssetMapper + Stimulus (no Node build step). Postgres 16 via Doctrine ORM 3 / DBAL 4. Admin via EasyAdmin (highest version compatible with Symfony 8) with ownership-scoped queries and a Symfony voter for fine-grained access. Three-role model (`ROLE_USER`, `ROLE_ORGANIZER`, `ROLE_ADMIN`). Public routes are stateless and slug-keyed; the QR code points at `/e/{slug}` and the user-shareable URL is `/e/{slug}/photos?t=…&w=…`. Time window is a UX filter, not a security boundary.

**Tech Stack:** PHP 8.5, Symfony 8.1 (webapp), Doctrine ORM 3 + DBAL 4, EasyAdmin (latest Symfony-8-compatible), PostgreSQL 16, Twig + AssetMapper + Stimulus, Docker Compose (php-fpm + nginx + postgres + mailpit), PHPStan level 10 (with `phpstan-symfony` + `phpstan-doctrine`), Rector PHP 8.5 set, PHPUnit 13, GrumPHP 2 (existing).

**⚠️ Bleeding-edge risk:** Symfony 8.1, PHP 8.5, PHPStan 10, EasyAdmin latest are all freshly released. Task 1 Step 9 is the gate — if composer can't resolve EasyAdmin (or phpstan-symfony, phpstan-doctrine, dama/doctrine-test-bundle) against Symfony 8.1, halt and surface the choice rather than silently downgrade.

---

## Project Outline

### Domain model (final shape for this plan — Photo deferred)

```
User              id, email (unique), password (hashed),
                  roles: string[] (e.g. ['ROLE_ORGANIZER']),
                  displayName

EventCollection   id, slug (unique), name, description,
                  owner: ManyToOne<User>

Event             id, slug (unique), name, description,
                  date (DateTimeImmutable, date-only),
                  startsAt (DateTimeImmutable, nullable),
                  endsAt (DateTimeImmutable, nullable),
                  defaultWindowMinutes: int (nullable),
                  owner: ManyToOne<User>,
                  collection: ManyToOne<EventCollection>(nullable)
```

`Event::DEFAULT_WINDOW_MINUTES` constant on the entity supplies the fallback when `defaultWindowMinutes` is `null`. Photo entity is **not** added in this plan.

### Routes (final shape for this plan)

| Method | Path | Controller | Auth |
|---|---|---|---|
| GET | `/` | `Public\HomeController` | public |
| GET | `/e/{slug}` | `Public\EventController::landing` | public |
| GET | `/e/{slug}/photos` | `Public\EventController::photos` | public (stub) |
| GET / POST | `/login` | Security login | public |
| GET | `/logout` | firewall | n/a |
| ALL | `/admin/*` | EasyAdmin | `ROLE_ORGANIZER` |

### File structure (target after this plan)

```
compose.yaml                       — docker compose root
compose.override.yaml              — local overrides (gitignored later if needed)
docker/php/Dockerfile              — PHP 8.5 + extensions + composer
docker/php/php.ini                 — opcache + dev tweaks
docker/nginx/default.conf          — vhost pointing at /public
bin/console                        — Symfony console (generated)
public/index.php                   — front controller (generated)
config/                            — Symfony config (generated + edited)
  packages/security.yaml           — firewalls, providers, password hasher
  packages/doctrine.yaml           — pg connection, ORM mapping (attributes)
  packages/easy_admin.yaml         — EasyAdmin defaults (if needed)
  routes.yaml                      — attribute-route loader
src/
  Kernel.php                       — (generated)
  Entity/User.php
  Entity/Event.php
  Entity/EventCollection.php
  Repository/UserRepository.php
  Repository/EventRepository.php
  Repository/EventCollectionRepository.php
  Security/                        — voters, login form authenticator if custom
  Controller/
    Public/HomeController.php
    Public/EventController.php
    Admin/DashboardController.php
    Admin/EventCrudController.php
    Admin/EventCollectionCrudController.php
    Admin/UserCrudController.php
templates/
  base.html.twig
  public/home.html.twig
  public/event/landing.html.twig
  public/event/photos.html.twig
  security/login.html.twig
assets/
  app.js
  controllers/share_controller.js
  styles/app.css
migrations/                        — generated
tests/
  Unit/...
  Functional/...
docs/superpowers/plans/2026-06-09-event-photos-foundation.md  (this file)
.env                               — committed defaults
.env.local                         — host-specific (gitignored)
```

### Decisions locked in

- PHP **8.5**, PHPStan level **10**, Symfony 8.1, Doctrine ORM 3.
- All commands run **inside the php container** (`docker compose exec php …`).
- Branch convention: `feature/<issue-number>-<slug>`. Commit messages must begin with the issue number (existing GrumPHP rule). **One GitHub issue per build task** below.
- Mailpit included in compose for future password-reset / SSO work; not actually used in this plan.
- Photo entity **deferred** entirely — not added, not migrated.
- Global default window lives as `Event::DEFAULT_WINDOW_MINUTES = 30` constant.
- Ports: nginx `:8080`, postgres `:5432`, mailpit web `:8025`.

### Risks / notes flagged during planning

1. **PHPStan level 10 friction**: requires `phpstan-symfony` + `phpstan-doctrine` extensions. Even with those, expect to add a small, justified ignore list (e.g. EasyAdmin's `configureFields()` iterator). Each ignore must have a comment.
2. **PHPCS PSR-12 vs Symfony attributes**: PSR-12 is largely compatible, but Symfony attribute spacing can trip up some sniffs. We'll loosen specific sniffs only if they fire — never blanket-disable.
3. **phpmnd**: will fire on Symfony scaffolding. Whitelist `src/Kernel.php` and migrations directories.
4. **rector.php currently targets PHP_83**: Task 1 corrects to `PhpVersion::PHP_85`.
5. **`require: php ^8.5`** means the container needs PHP 8.5 — the Dockerfile pins that.
6. **EasyAdmin 4 + PHP 8.5 + Symfony 8.1**: confirm versions resolve at composer time; if EasyAdmin 4.x lags Symfony 8.1, fall back to the highest compatible 4.x release. Halt and ask before downgrading Symfony.
7. **Doctrine migrations bundle is required** — add explicitly (not always part of webapp meta).
8. **Test DB isolation**: use a separate database `app_test` and `dama/doctrine-test-bundle` for per-test transactions.

---

## Task 0: GitHub issues & branch prep

**Files:** none (admin-only)

- [ ] **Step 1: Create one GitHub issue per build task (1–6 below)**

Use the `gh` CLI from the project root. Titles must be stable because they feed branch names.

```bash
gh issue create --title "Docker + Symfony skeleton + tooling alignment" --body "See docs/superpowers/plans/2026-06-09-event-photos-foundation.md Task 1"
gh issue create --title "User entity + login + security firewall"        --body "See plan Task 2"
gh issue create --title "Event + EventCollection entities + migrations"  --body "See plan Task 3"
gh issue create --title "EasyAdmin dashboard + ownership voter"          --body "See plan Task 4"
gh issue create --title "Public landing page + share UX"                 --body "See plan Task 5"
gh issue create --title "Stub photo route"                               --body "See plan Task 6"
```

Record the assigned issue numbers — they replace `<N>` in the branch names below. Expected: `#1` through `#6` in order assuming no concurrent issue creation.

- [ ] **Step 2: Confirm branch-name rule covers `feature/<N>-…`**

Run: `grep -A2 git_branch_name grumphp.yml`
Expected: `whitelist: - /^(feature|hotfix|bugfix|release)\/\d+-/` — already correct, no change needed.

- [ ] **Step 3: Confirm commit messages will pass with `<N> - …` prefix**

The existing matcher accepts either a leading digit or a merge-commit pattern. Plan commits start with `<issue-number> -`, matching the existing convention.

---

## Task 1: Docker + Symfony skeleton + tooling alignment

**Branch:** `feature/1-docker-symfony-skeleton`

**Files:**
- Create: `compose.yaml`
- Create: `docker/php/Dockerfile`
- Create: `docker/php/php.ini`
- Create: `docker/nginx/default.conf`
- Create: `.dockerignore`
- Modify: `.gitignore`
- Modify: `composer.json` (add Symfony + Doctrine + EasyAdmin + ext-pgsql + phpstan extensions; update PHP requirement comment)
- Modify: `rector.php` (PHP_85)
- Modify: `phpstan.neon` (add `includes` for symfony/doctrine extensions, configure container xml)
- Modify: `grumphp.yml` (whitelist Kernel.php for phpmnd, allow migrations directory excludes)
- Generated by Symfony recipes: `bin/console`, `public/index.php`, `config/**`, `src/Kernel.php`, `.env`, `symfony.lock`, etc.
- Test: `tests/Smoke/HomepageSmokeTest.php`

- [ ] **Step 1: Create the feature branch**

```bash
git checkout -b feature/1-docker-symfony-skeleton
```

- [ ] **Step 2: Write the Dockerfile**

Create `docker/php/Dockerfile`:

```dockerfile
FROM php:8.5-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libicu-dev libzip-dev libonig-dev \
    && docker-php-ext-install -j$(nproc) pdo_pgsql intl zip opcache \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /app
```

- [ ] **Step 3: Write `docker/php/php.ini`**

```ini
memory_limit = 512M
opcache.enable = 1
opcache.enable_cli = 0
opcache.validate_timestamps = 1
opcache.jit = tracing
opcache.jit_buffer_size = 64M
date.timezone = UTC
```

- [ ] **Step 4: Write the nginx vhost**

Create `docker/nginx/default.conf`:

```nginx
server {
    listen 80 default_server;
    server_name _;
    root /app/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass php:9000;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }

    client_max_body_size 32M;
}
```

- [ ] **Step 5: Write `compose.yaml`**

```yaml
services:
  php:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    volumes:
      - ./:/app:delegated
    environment:
      DATABASE_URL: "postgresql://app:app@postgres:5432/app?serverVersion=16&charset=utf8"
      MAILER_DSN: "smtp://mailpit:1025"
      APP_ENV: dev
      PHP_IDE_CONFIG: "serverName=event-photos"
    depends_on:
      postgres:
        condition: service_healthy
      mailpit:
        condition: service_started

  nginx:
    image: nginx:1.27-alpine
    volumes:
      - ./public:/app/public:ro
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    ports:
      - "8080:80"
    depends_on:
      - php

  postgres:
    image: postgres:16-alpine
    environment:
      POSTGRES_USER: app
      POSTGRES_PASSWORD: app
      POSTGRES_DB: app
    volumes:
      - postgres_data:/var/lib/postgresql/data
    ports:
      - "5432:5432"
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U app"]
      interval: 2s
      timeout: 5s
      retries: 10

  mailpit:
    image: axllent/mailpit:latest
    ports:
      - "8025:8025"
      - "1025:1025"

volumes:
  postgres_data:
```

- [ ] **Step 6: Write `.dockerignore`**

```
.git
.idea
vendor
var
.phpunit.cache
node_modules
```

- [ ] **Step 7: Update `.gitignore`**

Append to existing file:

```
/.env.local
/.env.local.php
/.env.*.local
/var/
/public/bundles/
/public/assets/
/migrations/*
!/migrations/.gitkeep
.phpunit.result.cache
```

(Migrations themselves will be re-included in Step 18 after we generate the first one; the wildcard prevents stray artifacts until then.)

- [ ] **Step 8: Build the php image and confirm PHP 8.5 is present**

```bash
docker compose build php
docker compose run --rm php php -v
```
Expected: line starts with `PHP 8.5.`

- [ ] **Step 9: Add Symfony webapp + Doctrine + EasyAdmin via composer (inside the container)**

```bash
docker compose run --rm php composer require \
  symfony/framework-bundle:^8.1 \
  symfony/runtime:^8.1 \
  symfony/dotenv:^8.1 \
  symfony/yaml:^8.1 \
  symfony/console:^8.1 \
  symfony/twig-bundle:^8.1 \
  symfony/asset-mapper:^8.1 \
  symfony/asset:^8.1 \
  symfony/stimulus-bundle \
  symfony/ux-turbo \
  symfony/form:^8.1 \
  symfony/validator:^8.1 \
  symfony/security-bundle:^8.1 \
  symfony/translation:^8.1 \
  symfony/mailer:^8.1 \
  doctrine/orm:^3 \
  doctrine/dbal:^4 \
  doctrine/doctrine-bundle:^2.13 \
  doctrine/doctrine-migrations-bundle:^3.4 \
  easycorp/easyadmin-bundle

docker compose run --rm php composer require --dev \
  symfony/maker-bundle \
  symfony/web-profiler-bundle:^8.1 \
  symfony/debug-bundle:^8.1 \
  phpstan/phpstan-symfony:^2 \
  phpstan/phpstan-doctrine:^2 \
  dama/doctrine-test-bundle \
  symfony/browser-kit:^8.1 \
  symfony/css-selector:^8.1
```

Expected: composer completes, generates `bin/console`, `public/index.php`, `config/`, `src/Kernel.php`, `.env`, `symfony.lock`.

If EasyAdmin (or any extension) fails to resolve against Symfony 8.1, **stop** and report the conflict. Do not silently downgrade Symfony — surface the choice (wait for compatible release, drop to Symfony 7.4 LTS, or swap admin tooling).

- [ ] **Step 10: Update `composer.json` PSR-4 to coexist with Symfony default**

Symfony's recipe will offer to add `App\\` PSR-4 mapping. The project already uses `Jwderoos\\EventPhotos\\`. Open `composer.json` and confirm autoload contains:

```json
"autoload": {
    "psr-4": {
        "App\\": "src/",
        "Jwderoos\\EventPhotos\\": "src/"
    }
}
```

Then **remove** the `Jwderoos\\EventPhotos\\` entry. Standard Symfony convention uses `App\\`; matching it avoids EasyAdmin/maker friction. Run:

```bash
docker compose run --rm php composer dump-autoload
```

- [ ] **Step 11: Fix rector PHP version**

Edit `rector.php` line ~56:

```php
->withPhpVersion(PhpVersion::PHP_85)
```

- [ ] **Step 12: Wire phpstan extensions and container XML**

Replace `phpstan.neon` contents with:

```neon
includes:
    - vendor/phpstan/phpstan-symfony/extension.neon
    - vendor/phpstan/phpstan-doctrine/extension.neon
    - vendor/phpstan/phpstan-symfony/rules.neon
    - vendor/phpstan/phpstan-doctrine/rules.neon

parameters:
    parallel:
        maximumNumberOfProcesses: 5
        jobSize: 5
        minimumNumberOfJobsPerProcess: 1
    reportUnmatchedIgnoredErrors: true
    treatPhpDocTypesAsCertain: false
    level: 10
    paths:
        - src
        - tests
    excludePaths:
        - src/Kernel.php
    symfony:
        containerXmlPath: var/cache/dev/App_KernelDevDebugContainer.xml
    doctrine:
        objectManagerLoader: tests/object-manager.php
    ignoreErrors: []
```

We will create `tests/object-manager.php` in Task 3 when Doctrine is configured; until then PHPStan still works without doctrine deep introspection — keep the `doctrine.objectManagerLoader` line but PHPStan will skip it gracefully if the file is missing.

- [ ] **Step 13: Configure grumphp for the Symfony layout**

Edit `grumphp.yml`. Under `phpmnd:` add `exclude_path: [Kernel.php]`. Under `phpcpd:` change `directory: ['src/']` to `directory: ['src/']` and add `exclude: ['src/Kernel.php']`. Under `phpcs:` add `ignore_patterns: ['Kernel.php', 'migrations/']`. Under `git_commit_message.matchers`, keep the existing rule — it already accepts a leading digit.

Add at the top under `grumphp.tasks` a guard so generated migrations don't fail unrelated tasks:

```yaml
        phpstan:
            configuration: phpstan.neon
            triggered_by:
                - 'php'
            metadata:
                priority: 100
```

- [ ] **Step 14: Set Postgres in `.env`**

The Symfony recipe creates `.env`. Edit it so `DATABASE_URL` matches compose:

```
DATABASE_URL="postgresql://app:app@postgres:5432/app?serverVersion=16&charset=utf8"
APP_SECRET=change-me-locally
```

- [ ] **Step 15: Start the stack and verify Symfony serves**

```bash
docker compose up -d
sleep 3
curl -sS -o /dev/null -w "%{http_code}\n" http://localhost:8080/
```
Expected: `200`. (Symfony's welcome page is served at `/` until we replace it.)

- [ ] **Step 16: Write a smoke test for the homepage**

Create `tests/Smoke/HomepageSmokeTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomepageSmokeTest extends WebTestCase
{
    public function testHomepageReturnsSuccess(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }
}
```

- [ ] **Step 17: Run the smoke test to confirm it fails meaningfully**

```bash
docker compose exec -T php vendor/bin/phpunit tests/Smoke/HomepageSmokeTest.php
```

Expected: PASS on Symfony's welcome page **if** the kernel boots. If a kernel error appears, fix imports/wiring before continuing.

- [ ] **Step 18: Run the full quality gate**

```bash
docker compose exec -T php vendor/bin/grumphp run
```

Expected: all tasks pass. If `phpstan` fails on Kernel.php, confirm the `excludePaths` line landed. If `phpmnd` fires on generated code, add specific paths to its `exclude_path`.

- [ ] **Step 19: Commit**

```bash
git add -A
git commit -m "1 - scaffold Docker + Symfony 8.1 webapp + tooling alignment"
```

- [ ] **Step 20: Push and open PR**

```bash
git push -u origin feature/1-docker-symfony-skeleton
gh pr create --base main --fill
```

Merge to main before starting Task 2.

---

## Task 2: User entity + login + security firewall

**Branch:** `feature/2-user-auth`

**Files:**
- Create: `src/Entity/User.php`
- Create: `src/Repository/UserRepository.php`
- Create: `src/Controller/SecurityController.php`
- Create: `templates/security/login.html.twig`
- Create: `templates/base.html.twig` (if not yet present from recipes; otherwise modify)
- Modify: `config/packages/security.yaml`
- Modify: `config/packages/doctrine.yaml` (set ORM mapping type=attribute)
- Create: `migrations/Version20260609_0001.php` (auto-generated)
- Create: `tests/object-manager.php` (PHPStan helper)
- Test: `tests/Unit/Entity/UserTest.php`
- Test: `tests/Functional/Security/LoginTest.php`

- [ ] **Step 1: Branch**

```bash
git checkout main && git pull
git checkout -b feature/2-user-auth
```

- [ ] **Step 2: Set Doctrine to attribute mapping**

Edit `config/packages/doctrine.yaml` so the `App` mapping is:

```yaml
doctrine:
    orm:
        mappings:
            App:
                type: attribute
                is_bundle: false
                dir: '%kernel.project_dir%/src/Entity'
                prefix: 'App\Entity'
                alias: App
```

- [ ] **Step 3: Create the test database config**

Edit `config/packages/doctrine.yaml` to ensure `when@test` overrides:

```yaml
when@test:
    doctrine:
        dbal:
            dbname_suffix: '_test%env(default::TEST_TOKEN)%'
```

Add to `.env.test` (create if missing):

```
KERNEL_CLASS='App\Kernel'
APP_SECRET='$ecretf0rTest'
SYMFONY_DEPRECATIONS_HELPER=999999
PANTHER_APP_ENV=panther
PANTHER_ERROR_SCREENSHOT_DIR=./var/error-screenshots
```

- [ ] **Step 4: Enable DAMA test bundle for transactional tests**

Edit `phpunit.xml`. Inside `<phpunit …>` add:

```xml
    <extensions>
        <bootstrap class="DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension"/>
    </extensions>
```

And inside the existing `<php>` block (add the block if missing), set the test DSN:

```xml
    <php>
        <env name="APP_ENV" value="test" force="true"/>
        <env name="DATABASE_URL" value="postgresql://app:app@postgres:5432/app?serverVersion=16&amp;charset=utf8" force="true"/>
    </php>
```

(`dbname_suffix` makes the actual database `app_test`.)

- [ ] **Step 5: Write the failing unit test for the User entity**

Create `tests/Unit/Entity/UserTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testNewUserHasUserRoleByDefault(): void
    {
        $user = new User('alice@example.com', 'Alice');

        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testAddingOrganizerRoleDoesNotDuplicateUserRole(): void
    {
        $user = new User('alice@example.com', 'Alice');
        $user->addRole('ROLE_ORGANIZER');

        self::assertSame(['ROLE_ORGANIZER', 'ROLE_USER'], $user->getRoles());
    }

    public function testUserIdentifierIsEmail(): void
    {
        $user = new User('alice@example.com', 'Alice');

        self::assertSame('alice@example.com', $user->getUserIdentifier());
    }
}
```

- [ ] **Step 6: Run it to confirm it fails**

```bash
docker compose exec -T php vendor/bin/phpunit tests/Unit/Entity/UserTest.php
```
Expected: FAIL — `App\Entity\User` does not exist.

- [ ] **Step 7: Create `src/Entity/User.php`**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_users_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 180)]
    private string $email;

    #[ORM\Column(type: 'string', length: 120)]
    private string $displayName;

    #[ORM\Column(type: 'string')]
    private string $password = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    public function __construct(string $email, string $displayName)
    {
        $this->email = $email;
        $this->displayName = $displayName;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): void
    {
        $this->displayName = $displayName;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashed): void
    {
        $this->password = $hashed;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    public function addRole(string $role): void
    {
        if (!in_array($role, $this->roles, true)) {
            $this->roles[] = $role;
        }
    }

    public function removeRole(string $role): void
    {
        $this->roles = array_values(array_filter($this->roles, static fn (string $r): bool => $r !== $role));
    }

    public function eraseCredentials(): void
    {
    }
}
```

- [ ] **Step 8: Create `src/Repository/UserRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }
}
```

- [ ] **Step 9: Create `tests/object-manager.php` for PHPStan**

```php
<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$kernel = new Kernel('dev', true);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
```

- [ ] **Step 10: Run the unit test to confirm it passes**

```bash
docker compose exec -T php vendor/bin/phpunit tests/Unit/Entity/UserTest.php
```
Expected: PASS (3/3).

- [ ] **Step 11: Generate and run the migration**

```bash
docker compose exec -T php bin/console doctrine:database:create --if-not-exists
docker compose exec -T php bin/console doctrine:database:create --if-not-exists --env=test
docker compose exec -T php bin/console make:migration --no-interaction
docker compose exec -T php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -T php bin/console doctrine:migrations:migrate --no-interaction --env=test
```

Expected: migration `migrations/Version20260609_xxxxxx.php` created, both DBs at HEAD.

- [ ] **Step 12: Configure security**

Replace `config/packages/security.yaml` with:

```yaml
security:
    password_hashers:
        Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface: 'auto'

    providers:
        app_users:
            entity:
                class: App\Entity\User
                property: email

    firewalls:
        dev:
            pattern: ^/(_(profiler|wdt)|css|images|js)/
            security: false

        main:
            lazy: true
            provider: app_users
            form_login:
                login_path: app_login
                check_path: app_login
                enable_csrf: true
                default_target_path: /admin
            logout:
                path: app_logout

    role_hierarchy:
        ROLE_ADMIN: [ROLE_ORGANIZER, ROLE_USER]
        ROLE_ORGANIZER: [ROLE_USER]

    access_control:
        - { path: ^/login, roles: PUBLIC_ACCESS }
        - { path: ^/admin, roles: ROLE_ORGANIZER }
        - { path: ^/, roles: PUBLIC_ACCESS }
```

- [ ] **Step 13: Create `src/Controller/SecurityController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authUtils): Response
    {
        return $this->render('security/login.html.twig', [
            'last_username' => $authUtils->getLastUsername(),
            'error'         => $authUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by firewall.');
    }
}
```

- [ ] **Step 14: Create `templates/security/login.html.twig`**

```twig
{% extends 'base.html.twig' %}

{% block title %}Sign in{% endblock %}

{% block body %}
    <main class="login">
        <h1>Sign in</h1>

        {% if error %}
            <div class="error">{{ error.messageKey|trans(error.messageData, 'security') }}</div>
        {% endif %}

        <form method="post" action="{{ path('app_login') }}">
            <input type="hidden" name="_csrf_token" value="{{ csrf_token('authenticate') }}">

            <label>
                Email
                <input type="email" name="_username" value="{{ last_username }}" required autofocus>
            </label>

            <label>
                Password
                <input type="password" name="_password" required>
            </label>

            <button type="submit">Sign in</button>
        </form>
    </main>
{% endblock %}
```

If `templates/base.html.twig` doesn't exist (Symfony recipe usually creates it), create a minimal one:

```twig
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{% block title %}Event Photos{% endblock %}</title>
    {% block stylesheets %}{% endblock %}
</head>
<body>
{% block body %}{% endblock %}
{% block javascripts %}{% endblock %}
</body>
</html>
```

- [ ] **Step 15: Write a console command to create a user (for E2E)**

Create `src/Command/CreateUserCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:create-user', description: 'Create a user')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED)
            ->addArgument('displayName', InputArgument::REQUIRED)
            ->addArgument('password', InputArgument::REQUIRED)
            ->addArgument('role', InputArgument::OPTIONAL, 'e.g. ROLE_ADMIN or ROLE_ORGANIZER', 'ROLE_ORGANIZER');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = new User(
            (string) $input->getArgument('email'),
            (string) $input->getArgument('displayName'),
        );
        $user->addRole((string) $input->getArgument('role'));
        $user->setPassword($this->hasher->hashPassword($user, (string) $input->getArgument('password')));

        $this->em->persist($user);
        $this->em->flush();

        $output->writeln('<info>User created.</info>');

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 16: Write the failing functional login test**

Create `tests/Functional/Security/LoginTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LoginTest extends WebTestCase
{
    public function testValidCredentialsLogInAndRedirectToAdmin(): void
    {
        $client = self::createClient();
        $container = self::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User('alice@example.com', 'Alice');
        $user->addRole('ROLE_ORGANIZER');
        $user->setPassword($hasher->hashPassword($user, 'correct horse battery'));
        $em->persist($user);
        $em->flush();

        $client->request('GET', '/login');
        $client->submitForm('Sign in', [
            '_username' => 'alice@example.com',
            '_password' => 'correct horse battery',
        ]);

        self::assertResponseRedirects('/admin');
    }

    public function testInvalidCredentialsShowError(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');
        $client->submitForm('Sign in', [
            '_username' => 'nobody@example.com',
            '_password' => 'nope',
        ]);

        $client->followRedirect();
        self::assertSelectorTextContains('.error', 'Invalid credentials');
    }
}
```

- [ ] **Step 17: Run it to confirm it fails**

```bash
docker compose exec -T php vendor/bin/phpunit tests/Functional/Security/LoginTest.php
```
Expected: FAIL — `/admin` route does not exist, redirect goes elsewhere.

- [ ] **Step 18: Add a temporary `/admin` placeholder to unblock the redirect**

Create `src/Controller/AdminPlaceholderController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminPlaceholderController extends AbstractController
{
    #[Route('/admin', name: 'app_admin_placeholder')]
    public function index(): Response
    {
        return new Response('<h1>Admin (placeholder)</h1>');
    }
}
```

This will be replaced by EasyAdmin's dashboard in Task 4. The placeholder keeps Task 2's tests honest.

- [ ] **Step 19: Run all tests**

```bash
docker compose exec -T php vendor/bin/phpunit
```
Expected: PASS for all tests in `tests/Unit/Entity/UserTest.php` and `tests/Functional/Security/LoginTest.php`; the existing smoke test continues to pass.

- [ ] **Step 20: Full quality gate**

```bash
docker compose exec -T php vendor/bin/grumphp run
```
Expected: all tasks pass. Fix any PHPStan errors locally (typed arrays, return types) before continuing — don't add ignores yet.

- [ ] **Step 21: Commit & PR**

```bash
git add -A
git commit -m "2 - add User entity, login form, security firewall"
git push -u origin feature/2-user-auth
gh pr create --base main --fill
```

Merge before Task 3.

---

## Task 3: Event + EventCollection entities + migrations

**Branch:** `feature/3-event-entities`

**Files:**
- Create: `src/Entity/Event.php`
- Create: `src/Entity/EventCollection.php`
- Create: `src/Repository/EventRepository.php`
- Create: `src/Repository/EventCollectionRepository.php`
- Create: migration file (generated)
- Test: `tests/Unit/Entity/EventTest.php`
- Test: `tests/Unit/Entity/EventCollectionTest.php`

- [ ] **Step 1: Branch**

```bash
git checkout main && git pull && git checkout -b feature/3-event-entities
```

- [ ] **Step 2: Write failing tests for `EventCollection`**

Create `tests/Unit/Entity/EventCollectionTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\EventCollection;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class EventCollectionTest extends TestCase
{
    public function testNewCollectionExposesGivenFields(): void
    {
        $owner = new User('o@x', 'Owner');
        $c = new EventCollection('summer-2026', 'Summer 2026', $owner);

        self::assertSame('summer-2026', $c->getSlug());
        self::assertSame('Summer 2026', $c->getName());
        self::assertSame($owner, $c->getOwner());
        self::assertNull($c->getDescription());
    }

    public function testDescriptionIsMutable(): void
    {
        $owner = new User('o@x', 'Owner');
        $c = new EventCollection('summer-2026', 'Summer 2026', $owner);
        $c->setDescription('All summer events');

        self::assertSame('All summer events', $c->getDescription());
    }
}
```

- [ ] **Step 3: Run; expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit tests/Unit/Entity/EventCollectionTest.php
```
Expected: FAIL — `App\Entity\EventCollection` not defined.

- [ ] **Step 4: Create `src/Entity/EventCollection.php`**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EventCollectionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EventCollectionRepository::class)]
#[ORM\Table(name: 'event_collections')]
#[ORM\UniqueConstraint(name: 'uniq_event_collections_slug', columns: ['slug'])]
class EventCollection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 120)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 200)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $owner;

    /** @var Collection<int, Event> */
    #[ORM\OneToMany(targetEntity: Event::class, mappedBy: 'collection')]
    private Collection $events;

    public function __construct(string $slug, string $name, User $owner)
    {
        $this->slug = $slug;
        $this->name = $name;
        $this->owner = $owner;
        $this->events = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): void { $this->slug = $slug; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): void { $this->description = $description; }
    public function getOwner(): User { return $this->owner; }
    public function setOwner(User $owner): void { $this->owner = $owner; }

    /** @return Collection<int, Event> */
    public function getEvents(): Collection { return $this->events; }

    public function __toString(): string { return $this->name; }
}
```

- [ ] **Step 5: Create `src/Repository/EventCollectionRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EventCollection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventCollection>
 */
final class EventCollectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventCollection::class);
    }
}
```

- [ ] **Step 6: Run; expect pass**

```bash
docker compose exec -T php vendor/bin/phpunit tests/Unit/Entity/EventCollectionTest.php
```
Expected: PASS (2/2).

- [ ] **Step 7: Write failing tests for `Event`**

Create `tests/Unit/Entity/EventTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Event;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    public function testNewEventExposesRequiredFields(): void
    {
        $owner = new User('o@x', 'Owner');
        $date  = new \DateTimeImmutable('2026-07-15');
        $event = new Event('summer-fest', 'Summer Fest', $date, $owner);

        self::assertSame('summer-fest', $event->getSlug());
        self::assertSame('Summer Fest', $event->getName());
        self::assertSame($date, $event->getDate());
        self::assertSame($owner, $event->getOwner());
        self::assertNull($event->getCollection());
        self::assertNull($event->getDefaultWindowMinutes());
    }

    public function testResolvedWindowMinutesFallsBackToEntityDefault(): void
    {
        $event = new Event('e', 'E', new \DateTimeImmutable('2026-07-15'), new User('o@x', 'Owner'));

        self::assertSame(Event::DEFAULT_WINDOW_MINUTES, $event->resolveWindowMinutes());
    }

    public function testResolvedWindowMinutesPrefersEventOverride(): void
    {
        $event = new Event('e', 'E', new \DateTimeImmutable('2026-07-15'), new User('o@x', 'Owner'));
        $event->setDefaultWindowMinutes(15);

        self::assertSame(15, $event->resolveWindowMinutes());
    }

    public function testDefaultWindowMinutesConstantIsPositive(): void
    {
        self::assertGreaterThan(0, Event::DEFAULT_WINDOW_MINUTES);
    }
}
```

- [ ] **Step 8: Run; expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit tests/Unit/Entity/EventTest.php
```
Expected: FAIL — `App\Entity\Event` not defined.

- [ ] **Step 9: Create `src/Entity/Event.php`**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'events')]
#[ORM\UniqueConstraint(name: 'uniq_events_slug', columns: ['slug'])]
class Event
{
    public const int DEFAULT_WINDOW_MINUTES = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 120)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 200)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $defaultWindowMinutes = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $owner;

    #[ORM\ManyToOne(targetEntity: EventCollection::class, inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: true)]
    private ?EventCollection $collection = null;

    public function __construct(string $slug, string $name, \DateTimeImmutable $date, User $owner)
    {
        $this->slug = $slug;
        $this->name = $name;
        $this->date = $date;
        $this->owner = $owner;
    }

    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): void { $this->slug = $slug; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): void { $this->description = $description; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable $date): void { $this->date = $date; }
    public function getStartsAt(): ?\DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(?\DateTimeImmutable $startsAt): void { $this->startsAt = $startsAt; }
    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(?\DateTimeImmutable $endsAt): void { $this->endsAt = $endsAt; }
    public function getDefaultWindowMinutes(): ?int { return $this->defaultWindowMinutes; }
    public function setDefaultWindowMinutes(?int $minutes): void { $this->defaultWindowMinutes = $minutes; }
    public function getOwner(): User { return $this->owner; }
    public function setOwner(User $owner): void { $this->owner = $owner; }
    public function getCollection(): ?EventCollection { return $this->collection; }
    public function setCollection(?EventCollection $collection): void { $this->collection = $collection; }

    public function resolveWindowMinutes(): int
    {
        return $this->defaultWindowMinutes ?? self::DEFAULT_WINDOW_MINUTES;
    }

    public function __toString(): string { return $this->name; }
}
```

- [ ] **Step 10: Create `src/Repository/EventRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
final class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    public function findOneBySlug(string $slug): ?Event
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
```

- [ ] **Step 11: Run; expect pass**

```bash
docker compose exec -T php vendor/bin/phpunit tests/Unit/Entity/EventTest.php
```
Expected: PASS (4/4).

- [ ] **Step 12: Generate and apply migration**

```bash
docker compose exec -T php bin/console make:migration --no-interaction
docker compose exec -T php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -T php bin/console doctrine:migrations:migrate --no-interaction --env=test
```

Expected: a new migration file with two `CREATE TABLE` statements (events, event_collections) and FK indexes.

- [ ] **Step 13: Run all tests + quality**

```bash
docker compose exec -T php vendor/bin/phpunit
docker compose exec -T php vendor/bin/grumphp run
```
Expected: all green.

- [ ] **Step 14: Commit & PR**

```bash
git add -A
git commit -m "3 - add Event and EventCollection entities + migration"
git push -u origin feature/3-event-entities
gh pr create --base main --fill
```

---

## Task 4: EasyAdmin dashboard + ownership voter

**Branch:** `feature/4-easyadmin-voter`

**Files:**
- Replace: `src/Controller/AdminPlaceholderController.php` → remove
- Create: `src/Controller/Admin/DashboardController.php`
- Create: `src/Controller/Admin/EventCrudController.php`
- Create: `src/Controller/Admin/EventCollectionCrudController.php`
- Create: `src/Controller/Admin/UserCrudController.php`
- Create: `src/Security/Voter/EventVoter.php`
- Create: `src/Security/Voter/EventCollectionVoter.php`
- Create: `src/Admin/OwnedQueryFilter.php` (helper trait or class)
- Modify: `config/packages/security.yaml` (no change expected if already covers `/admin`)
- Test: `tests/Functional/Admin/AdminAccessTest.php`
- Test: `tests/Functional/Admin/OwnershipScopingTest.php`
- Test: `tests/Unit/Security/EventVoterTest.php`

- [ ] **Step 1: Branch**

```bash
git checkout main && git pull && git checkout -b feature/4-easyadmin-voter
```

- [ ] **Step 2: Remove the placeholder**

```bash
rm src/Controller/AdminPlaceholderController.php
```

- [ ] **Step 3: Write failing test for `/admin` access control**

Create `tests/Functional/Admin/AdminAccessTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminAccessTest extends WebTestCase
{
    public function testAnonymousGetsRedirectedToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/admin');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testOrganizerSeesDashboard(): void
    {
        $client = self::createClient();
        $container = self::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User('alice@example.com', 'Alice');
        $user->addRole('ROLE_ORGANIZER');
        $user->setPassword($hasher->hashPassword($user, 'pw'));
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
    }
}
```

- [ ] **Step 4: Run; expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit tests/Functional/Admin/AdminAccessTest.php
```
Expected: FAIL — no `/admin` route now.

- [ ] **Step 5: Create the EasyAdmin dashboard**

`src/Controller/Admin/DashboardController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Event;
use App\Entity\EventCollection;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('Event Photos');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::linkToCrud('Events', 'fa fa-calendar', Event::class);
        yield MenuItem::linkToCrud('Collections', 'fa fa-folder', EventCollection::class);

        if ($this->isGranted('ROLE_ADMIN')) {
            yield MenuItem::linkToCrud('Users', 'fa fa-user', User::class);
        }
    }
}
```

Create `templates/admin/dashboard.html.twig`:

```twig
{% extends '@EasyAdmin/page/content.html.twig' %}

{% block content_title %}Welcome{% endblock %}

{% block main %}
    <p>Hello {{ app.user.displayName }}. Use the menu to manage events.</p>
{% endblock %}
```

- [ ] **Step 6: Create the Event CRUD controller with ownership filtering**

`src/Controller/Admin/EventCrudController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Event;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use Symfony\Bundle\SecurityBundle\Security;

final class EventCrudController extends AbstractCrudController
{
    public function __construct(private readonly Security $security)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Event::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Event')
            ->setEntityLabelInPlural('Events')
            ->setDefaultSort(['date' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('slug')->setHelp('Used in the public QR URL: /e/{slug}');
        yield TextField::new('name');
        yield TextareaField::new('description')->hideOnIndex();
        yield DateField::new('date');
        yield DateTimeField::new('startsAt')->hideOnIndex();
        yield DateTimeField::new('endsAt')->hideOnIndex();
        yield IntegerField::new('defaultWindowMinutes')
            ->setHelp(sprintf('Minutes around "now" to search. Empty → default %d.', Event::DEFAULT_WINDOW_MINUTES));
        yield AssociationField::new('collection')->hideOnIndex();

        if ($this->security->isGranted('ROLE_ADMIN')) {
            yield AssociationField::new('owner');
        }
    }

    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters,
    ): QueryBuilder {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            $qb->andWhere('1 = 0');

            return $qb;
        }

        if (!$this->security->isGranted('ROLE_ADMIN')) {
            $qb->andWhere(sprintf('%s.owner = :owner', $qb->getRootAliases()[0]))
               ->setParameter('owner', $user);
        }

        return $qb;
    }
}
```

- [ ] **Step 7: Create the EventCollection CRUD controller (same scoping pattern)**

`src/Controller/Admin/EventCollectionCrudController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\EventCollection;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use Symfony\Bundle\SecurityBundle\Security;

final class EventCollectionCrudController extends AbstractCrudController
{
    public function __construct(private readonly Security $security)
    {
    }

    public static function getEntityFqcn(): string
    {
        return EventCollection::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('slug');
        yield TextField::new('name');
        yield TextareaField::new('description')->hideOnIndex();

        if ($this->security->isGranted('ROLE_ADMIN')) {
            yield AssociationField::new('owner');
        }
    }

    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters,
    ): QueryBuilder {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            $qb->andWhere('1 = 0');

            return $qb;
        }

        if (!$this->security->isGranted('ROLE_ADMIN')) {
            $qb->andWhere(sprintf('%s.owner = :owner', $qb->getRootAliases()[0]))
               ->setParameter('owner', $user);
        }

        return $qb;
    }
}
```

- [ ] **Step 8: Create the User CRUD controller (admin-only)**

`src/Controller/Admin/UserCrudController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class UserCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield EmailField::new('email');
        yield TextField::new('displayName');
        yield ChoiceField::new('roles')
            ->setChoices(['Organizer' => 'ROLE_ORGANIZER', 'Admin' => 'ROLE_ADMIN'])
            ->allowMultipleChoices()
            ->renderExpanded();
    }
}
```

(Note: password setting will need a dedicated form; for now users are created via `app:create-user` CLI. Flag this for follow-up.)

- [ ] **Step 9: Create the EventVoter**

`src/Security/Voter/EventVoter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Event;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Event>
 */
final class EventVoter extends Voter
{
    public const string EDIT = 'EVENT_EDIT';
    public const string DELETE = 'EVENT_DELETE';
    public const string VIEW = 'EVENT_VIEW';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::DELETE, self::VIEW], true)
            && $subject instanceof Event;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        \assert($subject instanceof Event);

        return $subject->getOwner() === $user;
    }
}
```

- [ ] **Step 10: Create the EventCollectionVoter (same shape)**

`src/Security/Voter/EventCollectionVoter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\EventCollection;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, EventCollection>
 */
final class EventCollectionVoter extends Voter
{
    public const string EDIT = 'COLLECTION_EDIT';
    public const string DELETE = 'COLLECTION_DELETE';
    public const string VIEW = 'COLLECTION_VIEW';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::DELETE, self::VIEW], true)
            && $subject instanceof EventCollection;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        \assert($subject instanceof EventCollection);

        return $subject->getOwner() === $user;
    }
}
```

- [ ] **Step 11: Unit-test the voter**

`tests/Unit/Security/EventVoterTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Event;
use App\Entity\User;
use App\Security\Voter\EventVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class EventVoterTest extends TestCase
{
    public function testOwnerCanEdit(): void
    {
        $owner = new User('o@x', 'Owner');
        $event = new Event('e', 'E', new \DateTimeImmutable('2026-07-15'), $owner);

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnMap([['ROLE_ADMIN', null, false]]);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($owner);

        $voter = new EventVoter($security);

        self::assertSame(1, $voter->vote($token, $event, [EventVoter::EDIT]));
    }

    public function testNonOwnerCannotEdit(): void
    {
        $owner   = new User('o@x', 'Owner');
        $intruder = new User('i@x', 'Intruder');
        $event = new Event('e', 'E', new \DateTimeImmutable('2026-07-15'), $owner);

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnMap([['ROLE_ADMIN', null, false]]);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($intruder);

        $voter = new EventVoter($security);

        self::assertSame(-1, $voter->vote($token, $event, [EventVoter::EDIT]));
    }

    public function testAdminAlwaysAllowed(): void
    {
        $owner   = new User('o@x', 'Owner');
        $admin   = new User('a@x', 'Admin');
        $event = new Event('e', 'E', new \DateTimeImmutable('2026-07-15'), $owner);

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnMap([['ROLE_ADMIN', null, true]]);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($admin);

        $voter = new EventVoter($security);

        self::assertSame(1, $voter->vote($token, $event, [EventVoter::EDIT]));
    }
}
```

Run:

```bash
docker compose exec -T php vendor/bin/phpunit tests/Unit/Security/EventVoterTest.php
```
Expected: PASS (3/3).

- [ ] **Step 12: Functional test: ownership scoping in the admin index**

Create `tests/Functional/Admin/OwnershipScopingTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Event;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class OwnershipScopingTest extends WebTestCase
{
    public function testOrganizerOnlySeesOwnEventsInTheAdminIndex(): void
    {
        $client = self::createClient();
        $container = self::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $alice = new User('alice@example.com', 'Alice');
        $alice->addRole('ROLE_ORGANIZER');
        $alice->setPassword($hasher->hashPassword($alice, 'pw'));

        $bob = new User('bob@example.com', 'Bob');
        $bob->addRole('ROLE_ORGANIZER');
        $bob->setPassword($hasher->hashPassword($bob, 'pw'));

        $em->persist($alice);
        $em->persist($bob);

        $em->persist(new Event('alice-event', 'Alice Event', new \DateTimeImmutable('2026-07-15'), $alice));
        $em->persist(new Event('bob-event',   'Bob Event',   new \DateTimeImmutable('2026-07-15'), $bob));
        $em->flush();

        $client->loginUser($alice);
        $crawler = $client->request('GET', '/admin?crudControllerFqcn=App%5CController%5CAdmin%5CEventCrudController&crudAction=index');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Alice Event', $client->getResponse()->getContent() ?: '');
        self::assertStringNotContainsString('Bob Event', $client->getResponse()->getContent() ?: '');
    }
}
```

- [ ] **Step 13: Run all tests + quality**

```bash
docker compose exec -T php vendor/bin/phpunit
docker compose exec -T php vendor/bin/grumphp run
```
Expected: green. If PHPStan flags `configureFields` due to `mixed` iterable returns, add a targeted ignore in `phpstan.neon` with a comment.

- [ ] **Step 14: Commit & PR**

```bash
git add -A
git commit -m "4 - add EasyAdmin dashboard with ownership scoping and voters"
git push -u origin feature/4-easyadmin-voter
gh pr create --base main --fill
```

---

## Task 5: Public landing page + share UX

**Branch:** `feature/5-public-landing`

**Files:**
- Create: `src/Controller/Public/HomeController.php`
- Create: `src/Controller/Public/EventController.php`
- Create: `templates/public/home.html.twig`
- Create: `templates/public/event/landing.html.twig`
- Create: `assets/controllers/share_controller.js`
- Modify: `assets/controllers.json` (register the Stimulus controller)
- Modify: `assets/app.js` (no change needed if generated correctly — but verify)
- Modify: `templates/base.html.twig` (add asset-mapper tags)
- Test: `tests/Functional/Public/EventLandingTest.php`

- [ ] **Step 1: Branch**

```bash
git checkout main && git pull && git checkout -b feature/5-public-landing
```

- [ ] **Step 2: Replace the smoke test with a real homepage test**

Edit `tests/Smoke/HomepageSmokeTest.php` to assert the homepage shows our app name (not Symfony's welcome page):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomepageSmokeTest extends WebTestCase
{
    public function testHomepageShowsAppName(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Event Photos');
    }
}
```

- [ ] **Step 3: Run; expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit tests/Smoke/HomepageSmokeTest.php
```
Expected: FAIL — Symfony welcome page does not contain `<h1>Event Photos`.

- [ ] **Step 4: Implement `HomeController`**

`src/Controller/Public/HomeController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Public;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'public_home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('public/home.html.twig');
    }
}
```

And the template `templates/public/home.html.twig`:

```twig
{% extends 'base.html.twig' %}

{% block title %}Event Photos{% endblock %}

{% block body %}
    <main>
        <h1>Event Photos</h1>
        <p>Scan a QR code at an event to view your photos.</p>
    </main>
{% endblock %}
```

- [ ] **Step 5: Run the homepage test; expect pass**

```bash
docker compose exec -T php vendor/bin/phpunit tests/Smoke/HomepageSmokeTest.php
```
Expected: PASS.

- [ ] **Step 6: Write the failing event-landing test**

Create `tests/Functional/Public/EventLandingTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EventLandingTest extends WebTestCase
{
    public function testLandingShowsEventNameAndShareButton(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('o@x', 'Owner');
        $owner->setPassword('x');
        $em->persist($owner);
        $em->persist(new Event('summer-fest', 'Summer Fest', new \DateTimeImmutable('2026-07-15'), $owner));
        $em->flush();

        $client->request('GET', '/e/summer-fest');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Summer Fest');
        self::assertSelectorExists('a[data-action*="share_controller"]');
        self::assertSelectorExists('a[href*="/e/summer-fest/photos?t="]');
    }

    public function testLandingReturns404ForUnknownSlug(): void
    {
        $client = self::createClient();
        $client->request('GET', '/e/does-not-exist');

        self::assertResponseStatusCodeSame(404);
    }
}
```

- [ ] **Step 7: Run; expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit tests/Functional/Public/EventLandingTest.php
```
Expected: FAIL — `/e/{slug}` route missing.

- [ ] **Step 8: Implement `EventController` (landing only — photos is Task 6)**

`src/Controller/Public/EventController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\Event;
use App\Repository\EventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/e/{slug}', requirements: ['slug' => '[a-z0-9-]+'])]
final class EventController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'public_event_landing', methods: ['GET'])]
    public function landing(string $slug): Response
    {
        $event = $this->resolve($slug);
        $now = $this->clock->now();

        return $this->render('public/event/landing.html.twig', [
            'event'         => $event,
            'now'           => $now,
            'windowMinutes' => $event->resolveWindowMinutes(),
            'photosUrl'     => $this->generateUrl('public_event_photos', [
                'slug' => $event->getSlug(),
                't'    => $now->format(\DateTimeInterface::ATOM),
                'w'    => $event->resolveWindowMinutes(),
            ]),
        ]);
    }

    private function resolve(string $slug): Event
    {
        $event = $this->events->findOneBySlug($slug);

        if ($event === null) {
            throw new NotFoundHttpException(sprintf('No event for slug "%s".', $slug));
        }

        return $event;
    }
}
```

(`photos` action will be added in Task 6. The route name `public_event_photos` is referenced here — the URL generator will fail until Task 6 lands. To make the test pass now, add the photos route as a no-op redirect stub here too, and replace in Task 6.)

Add temporary photos route in the same controller (will be replaced in Task 6):

```php
    #[Route('/photos', name: 'public_event_photos', methods: ['GET'])]
    public function photos(string $slug): Response
    {
        $event = $this->resolve($slug);

        return new Response('photos placeholder for ' . $event->getName());
    }
```

- [ ] **Step 9: Create the landing template**

`templates/public/event/landing.html.twig`:

```twig
{% extends 'base.html.twig' %}

{% block title %}{{ event.name }}{% endblock %}

{% block body %}
    <main {{ stimulus_controller('share') }}>
        <h1>{{ event.name }}</h1>

        {% if event.description %}
            <p>{{ event.description }}</p>
        {% endif %}

        <p>
            Current time: <time datetime="{{ now|date('c') }}">{{ now|date('H:i') }}</time><br>
            Window: ±{{ windowMinutes }} minutes
        </p>

        <a
            href="{{ photosUrl }}"
            class="btn-primary"
            data-action="click->share#share"
            data-share-url-value="{{ url('public_event_photos', {slug: event.slug, t: now|date('c'), w: windowMinutes}) }}"
            data-share-title-value="{{ event.name }} — Photos"
            data-share-text-value="My photos from {{ event.name }}"
        >
            Show my photos
        </a>

        <button
            type="button"
            data-action="click->share#share"
            data-share-url-value="{{ url('public_event_photos', {slug: event.slug, t: now|date('c'), w: windowMinutes}) }}"
            data-share-title-value="{{ event.name }} — Photos"
            data-share-text-value="My photos from {{ event.name }}"
        >
            Share
        </button>
    </main>
{% endblock %}
```

- [ ] **Step 10: Create the Stimulus share controller**

`assets/controllers/share_controller.js`:

```js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        url: String,
        title: String,
        text: String,
    };

    async share(event) {
        if (event.currentTarget.tagName !== 'A' || event.metaKey || event.ctrlKey) {
            // Let normal navigation happen on plain link clicks; only handle the share button.
            if (event.currentTarget.tagName === 'A') {
                return;
            }
        }

        event.preventDefault();

        const payload = {
            title: this.titleValue,
            text:  this.textValue,
            url:   this.urlValue,
        };

        if (navigator.share) {
            try {
                await navigator.share(payload);
                return;
            } catch (err) {
                if (err.name === 'AbortError') {
                    return;
                }
            }
        }

        await this.copyFallback(payload.url);
    }

    async copyFallback(url) {
        try {
            await navigator.clipboard.writeText(url);
            this.flash('Link copied');
        } catch (_err) {
            window.prompt('Copy this link', url);
        }
    }

    flash(message) {
        const el = document.createElement('div');
        el.textContent = message;
        el.setAttribute('role', 'status');
        el.style.cssText = 'position:fixed;bottom:1rem;left:50%;transform:translateX(-50%);background:#222;color:#fff;padding:0.5rem 1rem;border-radius:0.25rem;';
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 2000);
    }
}
```

- [ ] **Step 11: Register the controller in `assets/controllers.json`**

Open `assets/controllers.json` and ensure local controllers are auto-loaded (Symfony Stimulus does this by convention when the file exists in `assets/controllers/`). If the JSON has `"@symfony/stimulus-bundle"` config, no edit is needed. If a manual registration is required, add:

```json
{
    "controllers": {},
    "entrypoints": []
}
```

(StimulusBundle's auto-discovery handles `assets/controllers/*_controller.js` out of the box.)

- [ ] **Step 12: Add asset-mapper tags to `base.html.twig`**

Inside `<head>` add (or confirm) before `</head>`:

```twig
{% block stylesheets %}
    {{ importmap('app') }}
{% endblock %}
```

Inside the `<body>` block, ensure the stimulus startup is triggered by `app.js` (recipe-generated default already imports `bootstrap.js` which starts Stimulus). If `assets/app.js` is missing the bootstrap import, add:

```js
import './bootstrap.js';
```

- [ ] **Step 13: Run all tests**

```bash
docker compose exec -T php vendor/bin/phpunit
```
Expected: green.

- [ ] **Step 14: Manual smoke check via browser**

```bash
docker compose exec -T php bin/console app:create-user demo@example.com Demo password ROLE_ORGANIZER
docker compose exec -T php bin/console doctrine:query:sql "INSERT INTO events (slug,name,date,owner_id) VALUES ('demo','Demo Event', CURRENT_DATE, 1) ON CONFLICT DO NOTHING"
```

Open `http://localhost:8080/e/demo` in a browser. Confirm: page renders, button text "Show my photos", "Share" button uses Web Share on mobile, copies to clipboard on desktop. Resize to mobile width and confirm the layout is acceptable.

- [ ] **Step 15: Quality gate**

```bash
docker compose exec -T php vendor/bin/grumphp run
```

- [ ] **Step 16: Commit & PR**

```bash
git add -A
git commit -m "5 - add public landing page with share UX"
git push -u origin feature/5-public-landing
gh pr create --base main --fill
```

---

## Task 6: Stub photo route

**Branch:** `feature/6-photos-stub`

**Files:**
- Modify: `src/Controller/Public/EventController.php` (replace inline stub with real action reading `t` and `w` query params)
- Create: `templates/public/event/photos.html.twig`
- Test: `tests/Functional/Public/EventPhotosStubTest.php`

- [ ] **Step 1: Branch**

```bash
git checkout main && git pull && git checkout -b feature/6-photos-stub
```

- [ ] **Step 2: Write the failing test**

Create `tests/Functional/Public/EventPhotosStubTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Public;

use App\Entity\Event;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EventPhotosStubTest extends WebTestCase
{
    public function testPhotosPageRendersWithTimestampAndWindow(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('o@x', 'Owner');
        $owner->setPassword('x');
        $em->persist($owner);
        $em->persist(new Event('summer-fest', 'Summer Fest', new \DateTimeImmutable('2026-07-15'), $owner));
        $em->flush();

        $client->request('GET', '/e/summer-fest/photos?t=2026-07-15T18:30:00%2B00:00&w=20');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Summer Fest');
        self::assertSelectorTextContains('[data-testid="window"]', '20');
        self::assertSelectorTextContains('[data-testid="timestamp"]', '18:30');
    }

    public function testPhotosPageFallsBackToEventDefaultWindowWhenMissing(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('o@x', 'Owner');
        $owner->setPassword('x');
        $em->persist($owner);
        $em->persist(new Event('summer-fest', 'Summer Fest', new \DateTimeImmutable('2026-07-15'), $owner));
        $em->flush();

        $client->request('GET', '/e/summer-fest/photos?t=2026-07-15T18:30:00%2B00:00');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="window"]', (string) Event::DEFAULT_WINDOW_MINUTES);
    }

    public function testInvalidTimestampReturns400(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User('o@x', 'Owner');
        $owner->setPassword('x');
        $em->persist($owner);
        $em->persist(new Event('summer-fest', 'Summer Fest', new \DateTimeImmutable('2026-07-15'), $owner));
        $em->flush();

        $client->request('GET', '/e/summer-fest/photos?t=not-a-date');

        self::assertResponseStatusCodeSame(400);
    }
}
```

- [ ] **Step 3: Run; expect failure**

```bash
docker compose exec -T php vendor/bin/phpunit tests/Functional/Public/EventPhotosStubTest.php
```
Expected: FAIL — the inline stub from Task 5 returns plain text, not HTML.

- [ ] **Step 4: Replace the stub `photos` action with a real implementation**

Edit `src/Controller/Public/EventController.php`. Remove the inline photos route and add a proper action:

```php
    #[Route('/photos', name: 'public_event_photos', methods: ['GET'])]
    public function photos(string $slug, \Symfony\Component\HttpFoundation\Request $request): Response
    {
        $event = $this->resolve($slug);

        $tRaw = (string) $request->query->get('t', '');
        $timestamp = $tRaw !== ''
            ? \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $tRaw)
            : $this->clock->now();

        if ($timestamp === false) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid timestamp');
        }

        $wRaw = $request->query->get('w');
        $window = is_numeric($wRaw) ? (int) $wRaw : $event->resolveWindowMinutes();

        if ($window < 1 || $window > 24 * 60) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid window');
        }

        return $this->render('public/event/photos.html.twig', [
            'event'     => $event,
            'timestamp' => $timestamp,
            'window'    => $window,
        ]);
    }
```

- [ ] **Step 5: Create the photos template**

`templates/public/event/photos.html.twig`:

```twig
{% extends 'base.html.twig' %}

{% block title %}{{ event.name }} — Photos{% endblock %}

{% block body %}
    <main>
        <h1>{{ event.name }}</h1>

        <p>
            Time:
            <time data-testid="timestamp" datetime="{{ timestamp|date('c') }}">{{ timestamp|date('H:i') }}</time>
        </p>
        <p>
            Window: ±<span data-testid="window">{{ window }}</span> minutes
        </p>

        <p><em>Photo ingest is not implemented yet. Your photos will appear here once added.</em></p>

        <p>
            <a href="{{ path('public_event_landing', {slug: event.slug}) }}">← Back to event</a>
        </p>
    </main>
{% endblock %}
```

- [ ] **Step 6: Run the new tests**

```bash
docker compose exec -T php vendor/bin/phpunit tests/Functional/Public/EventPhotosStubTest.php
```
Expected: PASS (3/3).

- [ ] **Step 7: Re-run the full suite + quality**

```bash
docker compose exec -T php vendor/bin/phpunit
docker compose exec -T php vendor/bin/grumphp run
```
Expected: all green.

- [ ] **Step 8: Commit & PR**

```bash
git add -A
git commit -m "6 - add stub photos page reading t and w query params"
git push -u origin feature/6-photos-stub
gh pr create --base main --fill
```

---

## Cross-cutting deferred items

These were brainstormed but **explicitly out of scope** for this plan:

- Photo entity, migration, ingest pipeline, time-window query
- SSO / OIDC integration
- Password reset flow
- Organizer self-signup
- User CRUD password field in EasyAdmin (currently CLI-only via `app:create-user`)
- CI pipeline (GitHub Actions)
- Production deployment / hosting
- Rate limiting on `/e/{slug}` and `/e/{slug}/photos`
- Cache headers and CDN strategy for the public pages

Each deserves its own brainstorming + plan when picked up.

---

## Self-Review (run after writing)

**Spec coverage:**
- Public UI `/e/{slug}` + share buttons — Task 5 ✓
- Public UI `/e/{slug}/photos?t=…&w=…` stub — Task 6 ✓
- Admin auth + EasyAdmin — Tasks 2 + 4 ✓
- Three-role model + ownership scoping — Tasks 2 + 4 ✓
- Domain model (User, EventCollection, Event) — Tasks 2 + 3 ✓
- Photo entity deferred — confirmed not in plan ✓
- Docker + tooling — Task 1 ✓
- Build order from HANDOVER preserved (steps 1–6; step 7 deferred) ✓

**Placeholder scan:** no "TBD", no "implement later", no "similar to". Every code block is concrete and pasteable.

**Type consistency:** `Event::DEFAULT_WINDOW_MINUTES` referenced consistently in Event entity, EventCrudController, photos action, photos test. `resolveWindowMinutes()` method name consistent in entity + controller + test. Route names (`public_event_landing`, `public_event_photos`, `admin`, `app_login`, `app_logout`) consistent across controllers, templates, and tests.

**Known wobble points to watch during execution:**
- EasyAdmin 4 vs Symfony 8.1 version resolution (Task 1 Step 9). Halt and report if composer fails.
- PHPStan level 10 against EasyAdmin's `configureFields()` — may need one targeted ignore.
- DAMA bundle requires the test database to exist beforehand — Task 2 Step 11 creates it.
- `dama/doctrine-test-bundle` version 8 may require PHPUnit 11+; we're on 13. If incompatible, drop to v7 and bump the schema-reset strategy accordingly.

---

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-06-09-event-photos-foundation.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — Execute tasks in this session using `superpowers:executing-plans`, batch execution with checkpoints.

**Which approach?**
