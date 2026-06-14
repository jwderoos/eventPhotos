# Long-lived admin sessions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move Symfony session storage from the ephemeral container filesystem to Postgres via `PdoSessionHandler`, and set a 30-day rolling cookie lifetime, so organizer sessions survive deploys and feel "set and forget".

**Architecture:** New `session.handler.pdo` service backed by the existing Doctrine PDO; framework session config switched to that handler with 30-day cookie/GC lifetime; canonical Postgres `sessions` table created via a hand-pasted Doctrine migration (documented exception to the no-hand-written-migrations rule because no entity exists to diff against). Anonymous public routes already do not touch the session — verified by audit task.

**Tech Stack:** Symfony 8, PHP 8.5, Doctrine ORM 3 / DBAL 4, PostgreSQL 16, PHPUnit 13.

**Spec:** `docs/superpowers/specs/2026-06-14-68-long-lived-admin-sessions-design.md`
**Issue:** [#68](https://github.com/jwderoos/eventPhotos/issues/68)

**Branch:** `feature/68-long-lived-admin-sessions` (GrumPHP enforces this prefix shape).

**Commit message convention:** every commit message must include `68` somewhere (GrumPHP gate). Use a leading `68 - ` style consistent with recent history (`git log --oneline -5`).

---

## File map

- Create: `src/Session/PdoSessionHandlerFactory.php` — factory pulling PDO from the existing Doctrine connection.
- Modify: `config/services.yaml` — register the factory + handler service.
- Modify: `config/packages/framework.yaml` — set `handler_id`, lifetime, gc, cookie attributes.
- Create: `migrations/Version<timestamp>.php` — `sessions` table SQL (hand-pasted canonical schema).
- Modify: `config/packages/doctrine.yaml` — add `schema_filter` so `doctrine:schema:validate` ignores the `sessions` table.
- Modify: `CLAUDE.md` — add "Sessions" subsection under Architecture.
- Create: `tests/Integration/Session/PdoSessionHandlerWiringTest.php` — DI resolves to `PdoSessionHandler`.
- Create: `tests/Integration/Session/PdoSessionRoundTripTest.php` — write/read against the test Postgres.
- Create: `tests/Functional/Session/RollingCookieTest.php` — second response carries a refreshed `Set-Cookie` lifetime.

---

## Task 1: Create the feature branch

**Files:** none.

- [ ] **Step 1: Create and check out the branch**

Run:
```bash
git checkout -b feature/68-long-lived-admin-sessions
```

Expected: `Switched to a new branch 'feature/68-long-lived-admin-sessions'`.

- [ ] **Step 2: Verify the branch name passes the GrumPHP gate locally**

The branch regex is `^(feature|hotfix|bugfix|release)/\d+-` — the name above matches. No command to run; just confirm.

---

## Task 2: Generate the sessions-table migration (TDD-light — schema first because tests depend on it)

**Files:**
- Create: `migrations/Version<new timestamp>.php`

This is the documented exception to "never hand-write migrations" because there is no Doctrine entity for `sessions`. The SQL is verbatim from Symfony's PdoSessionHandler docs for Postgres.

- [ ] **Step 1: Generate a blank migration**

Run:
```bash
bin/console doctrine:migrations:generate
```

Expected output ends with a path like `migrations/Version20260614<HHMMSS>.php`. Note the filename — you'll edit it in the next step.

- [ ] **Step 2: Replace the migration body with the canonical sessions schema**

Open the generated file. Note its exact class name (something like `Version2026061412XXXX`) — you'll preserve it. Replace the file contents with the block below, but substitute the real class name where shown as `<ClassNameFromGeneratedFile>`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class <ClassNameFromGeneratedFile> extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create sessions table for PdoSessionHandler. Hand-pasted from Symfony docs because there is no Doctrine entity to diff (#68).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE sessions (
                sess_id VARCHAR(128) NOT NULL PRIMARY KEY,
                sess_data BYTEA NOT NULL,
                sess_lifetime INTEGER NOT NULL,
                sess_time INTEGER NOT NULL
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sessions');
    }
}
```

- [ ] **Step 3: Apply the migration locally**

Run:
```bash
bin/console doctrine:migrations:migrate --no-interaction
```

Expected: `[OK] Successfully migrated ...`.

- [ ] **Step 4: Verify the table exists**

Run:
```bash
docker compose exec -T database psql -U app -d app -c '\d sessions'
```

Expected: shows the 4 columns (`sess_id`, `sess_data`, `sess_lifetime`, `sess_time`) and the PK on `sess_id`.

- [ ] **Step 5: Apply against the test DB**

Run:
```bash
bin/console doctrine:database:create --env=test --if-not-exists
bin/console doctrine:migrations:migrate --env=test --no-interaction
```

Expected: migration applied; if the test DB was already current, output `[OK] Already at the latest version`.

- [ ] **Step 6: Confirm schema:validate is still green**

Run:
```bash
bin/console doctrine:schema:validate --env=test
```

Expected: `[OK] The mapping files are correct.` AND `[OK] The database schema is in sync with the mapping files.`. The validator ignores `sessions` because it's not mapped.

- [ ] **Step 7: Commit**

```bash
git add migrations/
git commit -m "68 - add sessions table migration for PdoSessionHandler"
```

---

## Task 3: Add the PDO factory + session handler service

**Files:**
- Create: `src/Session/PdoSessionHandlerFactory.php`
- Modify: `config/services.yaml`

- [ ] **Step 1: Write the failing test (DI wiring)**

Create `tests/Integration/Session/PdoSessionHandlerWiringTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Session;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

final class PdoSessionHandlerWiringTest extends KernelTestCase
{
    public function testSessionHandlerPdoServiceResolvesToPdoSessionHandler(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $handler = $container->get('session.handler.pdo');

        self::assertInstanceOf(PdoSessionHandler::class, $handler);
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run:
```bash
vendor/bin/phpunit tests/Integration/Session/PdoSessionHandlerWiringTest.php
```

Expected: FAIL — `ServiceNotFoundException` for `session.handler.pdo`.

- [ ] **Step 3: Create the factory**

Create `src/Session/PdoSessionHandlerFactory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Session;

use Doctrine\DBAL\Connection;
use PDO;
use RuntimeException;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

final class PdoSessionHandlerFactory
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function create(): PdoSessionHandler
    {
        $pdo = $this->connection->getNativeConnection();

        if (!$pdo instanceof PDO) {
            throw new RuntimeException(
                'Expected the Doctrine connection to expose a PDO native connection; got '
                . (is_object($pdo) ? $pdo::class : gettype($pdo))
            );
        }

        return new PdoSessionHandler($pdo, [
            'db_table'        => 'sessions',
            'db_id_col'       => 'sess_id',
            'db_data_col'     => 'sess_data',
            'db_lifetime_col' => 'sess_lifetime',
            'db_time_col'     => 'sess_time',
            'lock_mode'       => PdoSessionHandler::LOCK_TRANSACTIONAL,
        ]);
    }
}
```

- [ ] **Step 4: Register the service**

Modify `config/services.yaml`. In the `services:` block (right after the `App\Service\Auth\GoogleOAuthClient: alias: ...` line, before `when@test:`), add:

```yaml
    App\Session\PdoSessionHandlerFactory:
        arguments:
            $connection: '@doctrine.dbal.default_connection'

    session.handler.pdo:
        class: Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler
        factory: ['@App\Session\PdoSessionHandlerFactory', 'create']
        public: true
```

`public: true` is required so the test container can fetch it by id.

- [ ] **Step 5: Run the wiring test to confirm it passes**

Run:
```bash
vendor/bin/phpunit tests/Integration/Session/PdoSessionHandlerWiringTest.php
```

Expected: PASS.

- [ ] **Step 6: Run phpstan + phpcs on the new file**

Run:
```bash
vendor/bin/phpstan analyse src/Session
vendor/bin/phpcs src/Session
```

Expected: both green.

- [ ] **Step 7: Commit**

```bash
git add src/Session/PdoSessionHandlerFactory.php config/services.yaml tests/Integration/Session/PdoSessionHandlerWiringTest.php
git commit -m "68 - register PdoSessionHandler service backed by the Doctrine PDO"
```

---

## Task 4: Round-trip test (handler actually writes to Postgres)

**Files:**
- Create: `tests/Integration/Session/PdoSessionRoundTripTest.php`

This test does NOT use `dama/doctrine-test-bundle`'s auto-rollback because `getNativeConnection()` may return the unwrapped PDO and bypass the transaction wrapper. We clean up explicitly.

- [ ] **Step 1: Write the test**

Create `tests/Integration/Session/PdoSessionRoundTripTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Session;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

final class PdoSessionRoundTripTest extends KernelTestCase
{
    private const SESSION_ID = 'roundtrip-test-session-id';

    protected function tearDown(): void
    {
        if (self::$booted) {
            $container = self::getContainer();
            /** @var Connection $conn */
            $conn = $container->get('doctrine.dbal.default_connection');
            $conn->executeStatement('DELETE FROM sessions WHERE sess_id = :id', ['id' => self::SESSION_ID]);
        }

        parent::tearDown();
    }

    public function testWriteThenReadReturnsSameData(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var PdoSessionHandler $handler */
        $handler = $container->get('session.handler.pdo');

        $handler->open('', 'PHPSESSID');
        try {
            $handler->write(self::SESSION_ID, 'hello-world');
            $read = $handler->read(self::SESSION_ID);
        } finally {
            $handler->close();
        }

        self::assertSame('hello-world', $read);
    }
}
```

- [ ] **Step 2: Run the test**

Run:
```bash
vendor/bin/phpunit tests/Integration/Session/PdoSessionRoundTripTest.php
```

Expected: PASS. (Requires the `sessions` table to exist in the test DB — done in Task 2 Step 5.)

- [ ] **Step 3: Confirm the table is clean after the test**

Run:
```bash
docker compose exec -T database psql -U app -d app_test -c 'SELECT count(*) FROM sessions;'
```

Expected: `0`. (If non-zero, the explicit teardown didn't run — debug before continuing.)

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/Session/PdoSessionRoundTripTest.php
git commit -m "68 - integration test: round-trip a session through PdoSessionHandler"
```

---

## Task 5: Switch framework session config to the new handler + long lifetime

**Files:**
- Modify: `config/packages/framework.yaml`

- [ ] **Step 1: Replace the session line with the new block**

Open `config/packages/framework.yaml`. The current relevant fragment is:

```yaml
framework:
    secret: '%env(APP_SECRET)%'

    # Note that the session will be started ONLY if you read or write from it.
    session: true
```

Replace `session: true` with:

```yaml
    session:
        handler_id: session.handler.pdo
        cookie_lifetime: 2592000     # 30 days, rolling
        gc_maxlifetime: 2592000
        cookie_secure: auto
        cookie_samesite: lax
        cookie_httponly: true
        gc_probability: 1
        gc_divisor: 1000
```

Leave the `when@test:` block exactly as it is — `storage_factory_id: session.storage.factory.mock_file` stays and continues to override storage for tests.

- [ ] **Step 2: Re-run all existing tests to confirm nothing regressed**

Run:
```bash
vendor/bin/phpunit
```

Expected: all green. The functional login + password reset tests must still pass — they use `mock_file` storage so they're unaffected by `handler_id`.

- [ ] **Step 3: Commit**

```bash
git add config/packages/framework.yaml
git commit -m "68 - point session storage at Postgres and bump lifetime to 30 days"
```

---

## Task 6: Rolling-cookie functional test

**Files:**
- Create: `tests/Functional/Session/RollingCookieTest.php`

The Set-Cookie behavior comes from `cookie_lifetime`, not the storage backend, so this test works against the `mock_file` test storage.

- [ ] **Step 1: Write the failing test**

Create `tests/Functional/Session/RollingCookieTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Session;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class RollingCookieTest extends WebTestCase
{
    private const THIRTY_DAYS_SECONDS = 2_592_000;
    private const TOLERANCE_SECONDS = 60;

    public function testLoginPageSetsSessionCookieWithThirtyDayLifetime(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/login');

        $cookie = $client->getResponse()->headers->getCookies()[0] ?? null;
        self::assertNotNull($cookie, 'Expected /login to issue a session cookie (CSRF token starts the session).');

        $expiresAt = $cookie->getExpiresTime();
        self::assertGreaterThan(0, $expiresAt, 'Cookie should have a non-zero expiry, not be a session cookie.');

        $delta = $expiresAt - time();
        self::assertEqualsWithDelta(self::THIRTY_DAYS_SECONDS, $delta, self::TOLERANCE_SECONDS,
            'Cookie expiry should be ~30 days out.');
    }
}
```

- [ ] **Step 2: Run the test**

Run:
```bash
vendor/bin/phpunit tests/Functional/Session/RollingCookieTest.php
```

Expected: PASS (the framework.yaml change from Task 5 already drives this).

- [ ] **Step 3: Commit**

```bash
git add tests/Functional/Session/RollingCookieTest.php
git commit -m "68 - functional test: session cookie carries 30-day lifetime"
```

---

## Task 7: Audit public routes for accidental session writes

**Files:** none (audit task). Outcome is documented in CLAUDE.md in Task 8.

- [ ] **Step 1: Grep public controllers and templates**

Run:
```bash
grep -rn "addFlash\|getSession\|SessionInterface\|csrf_token" \
  src/Controller/Public/ templates/public/ 2>/dev/null
```

Expected (current state): **no matches**. If any match appears, evaluate whether the route is PUBLIC_ACCESS and whether the session write is essential. If not essential, remove it in this task with a follow-up commit. If essential, document why in the CLAUDE.md "Sessions" note in Task 8.

- [ ] **Step 2: Confirm photo-serve, lightbox, QR routes don't start sessions**

Boot the dev stack and curl a public photo URL without cookies; confirm no `Set-Cookie` header comes back.

Run:
```bash
docker compose up -d
# pick any existing photo id from the dev DB, or skip if no photos
curl -sI http://localhost:8080/p/1/thumb.jpg | grep -i 'set-cookie' || echo 'no cookie set (expected)'
```

Expected: `no cookie set (expected)`. (If you have no photos yet, `curl -sI http://localhost:8080/` against a known PUBLIC_ACCESS route works too; the key is the absence of `Set-Cookie`.)

- [ ] **Step 3: Note the audit result**

Write a one-line note in your local scratch (you'll paste it into CLAUDE.md in Task 8). Format:

> Audit (YYYY-MM-DD, #68): no session writes found on PUBLIC_ACCESS routes outside `/login`, `/reset-password`, `/setup`, `/oauth/google/*`. Public photo/QR/lightbox routes do not issue `Set-Cookie`.

(No commit yet — folded into Task 8.)

---

## Task 8: Document the sessions story in CLAUDE.md

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Add the "Sessions" subsection**

Open `CLAUDE.md`. Under the `## Architecture` section, after the "Security" subsection and before "Federated identity (Google SSO)", insert a new `### Sessions` subsection:

```markdown
### Sessions

Sessions are stored in Postgres via Symfony's `PdoSessionHandler` (wired in `src/Session/PdoSessionHandlerFactory.php`, registered as `session.handler.pdo` in `config/services.yaml`, selected via `framework.session.handler_id` in `config/packages/framework.yaml`). The `sessions` table is intentionally not a Doctrine entity — `doctrine:schema:validate` ignores it. Cookie + GC lifetime is 30 days, rolling: every session-touching response refreshes the cookie's `Expires`. Anonymous public routes (photo serve, lightbox, QR) do not touch the session, so they do not create rows — verified in the audit recorded with #68. New public routes must not introduce session writes; re-run the audit grep (`grep -rn "addFlash\|getSession\|csrf_token" src/Controller/Public/ templates/public/`) if in doubt. Revoke a session with `DELETE FROM sessions WHERE sess_id = '…'`. The `when@test:` block keeps `storage_factory_id: session.storage.factory.mock_file`, so functional tests never touch the real table.
```

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "68 - document Postgres session storage and audit outcome"
```

---

## Task 9: Manual deploy-survival verification

**Files:** none.

- [ ] **Step 1: Bring the stack up clean**

Run:
```bash
docker compose up -d
bin/console doctrine:migrations:migrate --no-interaction
```

Expected: stack healthy, migrations current.

- [ ] **Step 2: Log in via the browser**

Open `http://localhost:8080/login`, sign in as an existing organizer (or create one with `bin/console app:create-user`). Confirm you land on `/admin`.

- [ ] **Step 3: Restart the php container**

Run:
```bash
docker compose restart php
```

Expected: php-fpm restarts.

- [ ] **Step 4: Re-load the admin page in the SAME browser**

Refresh `http://localhost:8080/admin`. Expected: you are still logged in (no redirect to `/login`). If you are redirected to `/login`, sessions did NOT survive the restart — debug before continuing.

- [ ] **Step 5: Inspect the session row in Postgres**

Run:
```bash
docker compose exec -T database psql -U app -d app -c 'SELECT sess_id, sess_lifetime FROM sessions;'
```

Expected: at least one row, `sess_lifetime` = `2592000`.

- [ ] **Step 6: No commit needed**

Verification only.

---

## Task 10: Full quality gate + push

**Files:** none.

- [ ] **Step 1: Run the full GrumPHP suite**

Run:
```bash
vendor/bin/grumphp run
```

Expected: all tasks pass (phpstan, phpcs, phpmnd, phpcpd, rector, securitychecker_roave, doctrine:schema:validate).

- [ ] **Step 2: Run the full test suite once more**

Run:
```bash
vendor/bin/phpunit
```

Expected: all green, including the three new test files.

- [ ] **Step 3: Push the branch**

Run:
```bash
git push -u origin feature/68-long-lived-admin-sessions
```

Expected: branch pushed; CI workflow starts. Watch `gh run watch` if you want live status.

- [ ] **Step 4: Open the PR (only when CI is green)**

Run:
```bash
gh pr create --title "68 - long-lived admin sessions backed by Postgres" --body "$(cat <<'EOF'
## Summary
- Switch Symfony session storage to Postgres via `PdoSessionHandler`.
- Bump cookie + GC lifetime to 30 days (rolling).
- Add `sessions` table migration (hand-pasted — no Doctrine entity to diff).
- Document storage + audit outcome in CLAUDE.md.

Closes #68.

## Test plan
- [x] Integration: handler service resolves to `PdoSessionHandler`.
- [x] Integration: round-trip write/read against test Postgres.
- [x] Functional: `/login` response carries a ~30-day session cookie.
- [x] Manual: logged in, restarted php container, still logged in.
- [x] `grumphp run` green; full `phpunit` green.

## Notes
- All currently logged-in users will be logged out exactly once when this ships (handler change invalidates existing filesystem sessions). After that, the deploy-survival property holds.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Expected: PR URL printed.

- [ ] **Step 5: Tick the #68 acceptance checkboxes**

In the PR description or directly on issue #68, confirm:
- [x] Admin session lifetime configurably long (and documented).
- [x] `docker compose up -d` / image rebuild does not log existing organizers out.
- [x] Documented in CLAUDE.md / deploy notes where sessions live now.
