# Long-lived admin sessions via Postgres

Issue: [#68](https://github.com/jwderoos/eventPhotos/issues/68) — Longer admin session lifetime + sessions surviving Docker deploys.

## Problem

Two pain points for organizers:

1. Session lifetime is too short — organizers get logged out of `/admin` more aggressively than is comfortable for their workflow (uploading and curating photos around an event).
2. Deploys invalidate sessions — sessions currently live on the php-fpm container's local filesystem (PHP default), so every container rebuild logs everyone out.

## Goals

- Admin sessions feel "set and forget" for active organizers (30 days of idle).
- A routine `docker compose up -d` or image rebuild does not log existing organizers out.
- Session storage requires no new infrastructure.

## Non-goals (YAGNI)

- Remember-me. A 30-day rolling session covers the comfort goal; remember-me adds a second cookie/token surface and a "less-trusted" auth state we don't need.
- Redis. Overkill for this app's scale when Postgres is already in the stack.
- Per-user session inspection or revocation UI. A future feature if/when wanted; revocation is a DB `DELETE` for now.
- Split anon vs logged-in lifetimes. One number for everyone.

## Design

### Storage: PdoSessionHandler on the existing Postgres

Symfony's `Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler` wired against the existing Doctrine connection's underlying PDO. No new connection, no new service to deploy.

- Register the handler as a service in `config/services.yaml`:
  - Service id: `session.handler.pdo`
  - Class: `PdoSessionHandler`
  - Constructor: a factory that pulls `PDO` from `doctrine.dbal.default_connection->getNativeConnection()`.
- Wire it via `framework.session.handler_id: session.handler.pdo` in `config/packages/framework.yaml`.
- The `when@test:` block keeps `storage_factory_id: session.storage.factory.mock_file` and does NOT override `handler_id`, so test runs never touch the real session table.

### Schema

```sql
CREATE TABLE sessions (
    sess_id        VARCHAR(128) NOT NULL PRIMARY KEY,
    sess_data      BYTEA        NOT NULL,
    sess_lifetime  INTEGER      NOT NULL,
    sess_time      INTEGER      NOT NULL
);
```

This is the canonical PdoSessionHandler schema for Postgres (per Symfony docs). It is intentionally NOT a Doctrine entity, so it lives outside the ORM mapping. `doctrine:schema:validate` DOES flag any table the live DB has but the mapping doesn't — to keep the CI/GrumPHP gate green, we add `schema_filter: ~^(?!sessions$).*~` to `config/packages/doctrine.yaml` so the validator (and `schema:update`) ignores `sessions`.

Generated via `bin/console doctrine:migrations:generate` and the SQL pasted into `up()` / `down()`. The migration must be hand-pasted because Doctrine has no entity to diff from — this is the documented exception to the "never hand-write a migration" rule, and the SQL is verbatim from Symfony docs (no auto-generated index/constraint names involved). The accompanying `schema_filter` change is what keeps the no-hand-written-migrations gate (`schema:validate`) green going forward.

### Lifetime

In `config/packages/framework.yaml`:

```yaml
framework:
    session:
        handler_id: session.handler.pdo
        cookie_lifetime: 2592000     # 30 days
        gc_maxlifetime: 2592000      # 30 days
        cookie_secure: auto
        cookie_samesite: lax
        cookie_httponly: true
        gc_probability: 1
        gc_divisor: 1000             # 1-in-1000 requests run GC
```

- `cookie_lifetime` and `gc_maxlifetime` agree at 30 days (2,592,000 s).
- Symfony's default behavior writes a fresh `Set-Cookie` with a refreshed `Expires` whenever the session is touched, giving us rolling idle expiry without extra plumbing.
- `cookie_secure: auto` keeps dev (http://localhost:8080) working while enforcing `Secure` in prod (HTTPS terminating at the upstream proxy; trusted proxies are already configured in `when@prod`).
- GC values are explicit so a reader doesn't need to look up PHP defaults to reason about how often expired rows get cleaned.

### Anonymous-session audit (one-time, part of this work)

Symfony's session is lazy-started: a row is only written when something reads or writes the session bag. Public routes that never touch the session create zero rows. To make sure we actually have that property:

Grep `src/Controller/Public/`, `src/Controller/Account/` (before the move — see below; or move first then audit), and all templates rendered from PUBLIC_ACCESS routes for:

- `addFlash(`
- `$session->`, `getSession()`, `SessionInterface`
- `csrf_token(` / `{{ csrf_token(` (CSRF forms start the session)
- `app.session` and `app.flashes` in Twig

Any finding on a PUBLIC_ACCESS route that's NOT an auth flow (`/login`, `/reset-password`, `/setup`, `/oauth/google/*`) is either removed or moved behind auth. The auth flows legitimately need a session for CSRF and post-login flash, and `/login` is the natural entry point.

Document the audit outcome in a short "Sessions" paragraph in `CLAUDE.md` so the constraint is visible to future contributors.

### Worker considerations

The `worker` compose service runs `messenger:consume` and does not serve HTTP. It does not touch sessions. No GC story needed for the worker — web requests handle GC, and the 1-in-1000 probability means any active deployment runs GC plenty.

### Docker / compose

No change to `compose.yaml`. The `database` service already has a `database_data` named volume that persists across container restarts. Sessions inherit that durability for free. The previous filesystem-on-container session store goes away with the config change — we don't need a volume for `var/sessions`.

### Tests

- `tests/Integration/Session/PdoSessionHandlerWiringTest.php` — boots the kernel, fetches the `session.handler.pdo` service, asserts it's a `PdoSessionHandler`.
- `tests/Integration/Session/PdoSessionRoundTripTest.php` — write a value through a fresh `PdoSessionHandler` instance pointed at the test Postgres, read it back, assert equality. Uses `dama/doctrine-test-bundle` so the row is rolled back at the end of the test.
- `tests/Functional/Session/RollingCookieTest.php` — boots the kernel with the prod-like handler env (or via a small test config override), GETs `/login` twice, asserts the second response's `Set-Cookie` carries a refreshed lifetime (rolling expiry confirmed).
- Existing functional tests (login, password reset, etc.) continue to use the `mock_file` storage factory configured in `when@test:`. They do not require the `sessions` table to exist.
- CI runs `doctrine:migrations:migrate --env=test` before `doctrine:schema:validate --env=test`. The migration will create the `sessions` table in the test DB, which is harmless: `schema:validate` ignores tables not backed by Doctrine entities, and the functional tests never touch it.

### Documentation

- `CLAUDE.md` — add a short "Sessions" section under "Architecture":
  - Stored in Postgres `sessions` table via `PdoSessionHandler`.
  - 30-day idle lifetime (rolling).
  - Anonymous public routes don't create sessions (audited; new public routes must not introduce session writes — re-run the audit grep if in doubt).
  - Revocation = `DELETE FROM sessions WHERE sess_id = '…'` (or all sessions for a user when we have user-scoped indexing — out of scope here).
- Issue checklist for #68 — tick all three boxes once shipped.

## Open questions / risks

- **Rolling expiry verification.** Symfony's default *should* refresh the cookie on every session-touching response, but the behavior depends on `cookie_lifetime` being non-zero (which it is here, 2,592,000). Verified with a small functional test: hit `/login` (anonymous), then hit it again, assert the second response's `Set-Cookie` carries an `Expires`/`Max-Age` consistent with a fresh 30-day window.
- **GC under low traffic.** 1-in-1000 probability against low admin traffic could let expired rows linger. Acceptable: at 30 days they're already past their useful life, and a future cron sweep is a trivial follow-up if the table grows. Document this in the spec, don't pre-build it.
- **Existing logged-in users.** When this ships, everyone gets logged out exactly once (handler switch invalidates old filesystem sessions). After that, the deploy-survival property holds. Mention this in the PR description; no migration tooling needed.

## Implementation order

1. Add `PdoSessionHandler` service + framework config (handler_id, lifetime, gc, cookie attrs).
2. Generate migration, paste the canonical schema, run locally, verify.
3. Audit public routes for session touches; remove/move any finding.
4. Add the two integration tests.
5. Update `CLAUDE.md`.
6. Verify locally: log in, restart the php container, confirm still logged in.
