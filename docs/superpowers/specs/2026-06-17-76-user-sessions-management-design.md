# #76 — User sessions management

**Status:** Design approved 2026-06-17
**Issue:** [#76](https://github.com/jwderoos/eventPhotos/issues/76)
**Builds on:** #68 (long-lived admin sessions; `PdoSessionHandler` + 30-day rolling cookie)

## Goal

Give every logged-in user a "Sessions" page where they can see their own active sessions and revoke any of them, and give `ROLE_ADMIN` the same view of any user's sessions for account-takeover response and offboarding. The current state ("only way to log a user out everywhere is `DELETE FROM sessions WHERE sess_id = '…'`") is a security and operability gap that this ticket closes.

## Non-goals (deferred to follow-ups)

- "Suspicious activity" detection (new country, new UA).
- Email notification on new session.
- IP allow/deny lists.
- 2FA / re-auth challenge before revoking.
- Automated monthly MMDB refresh (separate ticket; `bin/console app:geoip:update` is run manually for v1).

## Architecture

### Two-table strategy

Keep `sessions` (Postgres-managed by `PdoSessionHandler`, no Doctrine entity, already excluded by `schema_filter ~^(?!sessions$).*~` from #68). Add a new Doctrine entity `App\Entity\UserSession` mapped to a new `user_sessions` table holding all *display* metadata. The two are kept in sync by a Postgres `AFTER DELETE` trigger on `sessions` that cascades into `user_sessions`.

Rationale: `sessions` belongs to the framework; `user_sessions` belongs to the app. Modelling display data as its own table keeps the framework table untouched and keeps Doctrine happy. The cascade-on-delete-via-trigger is the one piece that can't be expressed through Doctrine — it has to live in the migration as hand-edited SQL (the explicit exception to the "never hand-write migrations" rule in `CLAUDE.md`, because ORMs can't model triggers).

### Data model

| column | type | notes |
|---|---|---|
| `sess_id` | `VARCHAR(128) PRIMARY KEY` | mirrors `sessions.sess_id` |
| `user_id` | `INT NOT NULL` | FK → `users.id`, cascade-on-delete from `users` |
| `ip` | `VARCHAR(45) NOT NULL` | IPv6 max length; plain string, not Postgres `INET` (no querying need, simpler Doctrine mapping) |
| `user_agent` | `TEXT NOT NULL` | raw UA string, verbatim — fallback display and admin debugging |
| `user_agent_display` | `VARCHAR(128) NULL` | normalized "Chrome 124 — macOS 14" from `whichbrowser/parser` |
| `country_code` | `CHAR(2) NULL` | ISO-3166 alpha-2, `null` on MMDB miss or private IP |
| `label` | `VARCHAR(64) NULL` | user-editable, server-trimmed, plain text |
| `created_at` | `TIMESTAMP NOT NULL` | |
| `last_seen_at` | `TIMESTAMP NOT NULL` | refreshed by `kernel.request` listener if older than 60s |

Index on `user_id` (covers the `WHERE user_id = ? ORDER BY last_seen_at DESC` list query). No FK to `sessions.sess_id`: `sessions` is not a Doctrine entity, so any FK to it would have to be hand-edited into the migration, and `doctrine:schema:validate` would complain unless the inverse side were modelled. Cascade is handled by the trigger; that's sufficient.

### Migration

Generate via `bin/console doctrine:migrations:diff` for the `UserSession` entity. Hand-edit `up()` and `down()` to bracket the trigger:

```sql
-- up(), appended after the auto-generated CREATE TABLE
CREATE OR REPLACE FUNCTION user_sessions_cascade_delete() RETURNS trigger AS $$
BEGIN
  DELETE FROM user_sessions WHERE sess_id = OLD.sess_id;
  RETURN OLD;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER on_sessions_delete
  AFTER DELETE ON sessions
  FOR EACH ROW EXECUTE FUNCTION user_sessions_cascade_delete();
```

```sql
-- down(), prepended before the auto-generated DROP TABLE
DROP TRIGGER IF EXISTS on_sessions_delete ON sessions;
DROP FUNCTION IF EXISTS user_sessions_cascade_delete();
```

`doctrine:schema:validate` stays green because the `sessions` table is already filtered out and the trigger lives entirely outside the Doctrine mapping.

## Listeners and lifecycle

### `App\EventListener\UserSessionLoginListener`

Subscribes to `Symfony\Component\Security\Http\Event\InteractiveLoginEvent`. Runs once per real sign-in. Reads:

- `Request::getClientIp()` — already correct behind the prod reverse proxy via `trusted_proxies` config (verified in `framework.yaml`).
- `Request::headers->get('User-Agent')` — raw UA string.
- `$request->getSession()->getId()` — the post-rotation session id (Symfony rotates on login for security; by the time `InteractiveLoginEvent` fires this is the new id).

Then delegates to a shared `App\Service\Session\UserSessionCreator` service which:

1. Parses UA via `WhichBrowser\Parser` → normalized display string (truncate to 128 chars).
2. Looks up country via `App\Service\Session\CountryResolver` → `?string`.
3. Persists a new `UserSession` row with `created_at = last_seen_at = now()`.

### `App\EventListener\UserSessionRequestListener`

Subscribes to `kernel.request` at a priority lower than the firewall (so the security token is resolved). Skips if not authenticated or if no session has been started. Does two things in order:

1. **Lazy create-on-missing.** If no `user_sessions` row exists for the current `sess_id`, call `UserSessionCreator` with the request data. Self-heals the rollout gap (existing logged-in sessions on deploy day) and any code path that authenticates without firing `InteractiveLoginEvent` (programmatic auth, tests, future SSO flows).
2. **Throttled `last_seen_at`.** If the row exists and `last_seen_at` is older than 60 seconds, run a single targeted DBAL `UPDATE user_sessions SET last_seen_at = NOW() WHERE sess_id = :id`. Don't hydrate the entity — direct DBAL is sub-millisecond and avoids unnecessary UoW work on every authenticated request.

Both writes happen synchronously inside the listener; sub-millisecond and not on the hot path for anonymous traffic.

### GeoIP

`App\Service\Session\CountryResolver` wraps `GeoIp2\Database\Reader`. Constructor receives the MMDB path (`%kernel.project_dir%/var/geoip/GeoLite2-Country.mmdb`). On missing file, logs once at boot via the `app` channel and returns `null` on every call. On private/loopback IPs (`192.168.*`, `10.*`, `172.16-31.*`, `127.*`, `::1`), short-circuits to `null` without hitting the reader. Lookup happens once at row creation; never per-request.

### MMDB updater command

`App\Command\Geoip\UpdateMmdbCommand` (route name `app:geoip:update`). Gated by `App\Service\Session\GeoIpFeatureFlag::isEnabled()` which mirrors the `GoogleOAuthFeatureFlag` pattern from the SSO work: empty `MAXMIND_LICENSE_KEY` → command `setHidden(true)` + returns failure (effectively 404).

When enabled, downloads the tarball from `https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-Country&suffix=tar.gz&license_key=...`, extracts the `.mmdb` from the tarball, atomically renames into `var/geoip/GeoLite2-Country.mmdb`. `var/geoip/.gitkeep` is committed; the `.mmdb` itself is `.gitignore`d.

### UA parser

Add `whichbrowser/parser` to composer. Parse once at row creation; the display string is `getBrowser()->toString() . ' — ' . getOs()->toString()`, truncated to 128 chars. The raw UA always stays in `user_agent` so the UI can show the full string on hover for ambiguous parses.

## Routes, controllers, authorization

All under existing namespaces; no new firewall config needed. `/account` is already gated `ROLE_USER` in `security.yaml`; `/admin/users/**` is already gated `ROLE_ADMIN`.

| route | controller | gate |
|---|---|---|
| `GET  /account/sessions` | `Account\SessionController::index` | `ROLE_USER` |
| `POST /account/sessions/{sessId}/revoke` | `Account\SessionController::revoke` | voter `MANAGE` on `UserSession` |
| `POST /account/sessions/revoke-others` | `Account\SessionController::revokeOthers` | `ROLE_USER` |
| `POST /account/sessions/{sessId}/label` | `Account\SessionController::label` | voter `MANAGE` |
| `GET  /admin/users/{id}/sessions` | `Admin\UserSessionController::index` | `ROLE_ADMIN` |
| `POST /admin/users/{id}/sessions/{sessId}/revoke` | `Admin\UserSessionController::revoke` | `ROLE_ADMIN` |

All POSTs are CSRF-guarded via `$this->isCsrfTokenValid('user_session_<action>', $token)` matching the existing pattern.

### Voter — `App\Security\Voter\UserSessionVoter`

Single attribute `MANAGE`. Admin-bypass-then-ownership pattern matching `EventVoter` / `PhotoVoter`:

- `ROLE_ADMIN` → grant.
- `$subject->getUser() === $token->getUser()` → grant.
- Else deny.

Admin routes use the `ROLE_ADMIN` access-control rule as the primary gate and don't run the voter on individual rows (same pattern as the rest of `/admin/users`).

### Revoke implementation

Single DBAL statement via the existing Doctrine connection:

```php
$this->connection->executeStatement(
    'DELETE FROM sessions WHERE sess_id = :id',
    ['id' => $sessId],
);
```

The trigger handles the `user_sessions` cleanup automatically.

"Revoke others" runs `DELETE FROM sessions WHERE sess_id IN (SELECT sess_id FROM user_sessions WHERE user_id = :uid AND sess_id != :current)`. Bounded by `user_id` so it can never touch another user's rows even if the current `sess_id` were spoofed.

## UX

`/account/sessions` — single page, plain Twig + Tailwind, no LiveComponents (matches the rest of the admin/account surface). Table columns:

| Current | Device | IP / Country | Last active | Label | Action |
|---|---|---|---|---|---|

- **Current**: green "Current session" pill on the active row, blank otherwise.
- **Device**: `user_agent_display` shown; raw `user_agent` on `title=""` hover.
- **IP / Country**: IP next to a country code (e.g. "NL") and small flag glyph (Unicode flag emoji or `<img>` of `/static/flags/{cc}.svg` — implementer's call, both work). If `country_code` is null, just show the IP.
- **Last active**: relative time ("2 minutes ago") using Symfony's `since` filter or equivalent. Tooltip shows the absolute timestamp.
- **Label**: inline form, POST to `/account/sessions/{sessId}/label`. Empty submit clears the label. No JS required.
- **Action**: revoke button as a `<button type="submit">` inside a small POST form. On the current-session row, the button is rendered as `<button disabled title="Use 'Sign out' in the header to end this session">`.

Top of the page: a single banner row with the page title, a "Sign out everywhere else" button (POST form), and a one-line privacy note: "Sessions older than 30 days are automatically removed."

`/admin/users/{id}/sessions` — same table minus the inline label edit (label is the user's choice; admin shouldn't overwrite) and minus the "revoke others" button (admin acts per-row). Top banner: "You are viewing {user.email}'s sessions" with a back-link to the user detail page.

## Tests

### Unit

- `UserSessionVoter` — own session, other user's session, admin overriding both.
- `Label` validation — length cap (64), control-char rejection, whitespace trim, null handling.
- `UserSessionRequestListener` throttle logic — `last_seen_at` older than 60s triggers UPDATE, fresher does not. Clock mocked.
- `CountryResolver` — null on missing MMDB, returns code on public IP hit, returns null on private/loopback IP (no reader call).

### Integration (real test Postgres, `dama/doctrine-test-bundle` transactional)

- `InteractiveLoginEvent` fires `UserSessionLoginListener` → one `user_sessions` row created with the post-rotation `sess_id`.
- Lazy create-on-missing fires from `UserSessionRequestListener` when the row is absent for the current authenticated user.
- `GeoIpFeatureFlag` — empty `MAXMIND_LICENSE_KEY` → `UpdateMmdbCommand` is hidden / fails fast (mirrors the existing `GoogleOAuthFeatureFlag` test pattern).

### Trigger test (special-cased)

`dama/doctrine-test-bundle` wraps each test in a transaction that rolls back at teardown, but PG triggers fire within the same transaction — so we can still assert trigger behavior inside one test. The test inserts a fake `sessions` row and a matching `user_sessions` row, deletes the `sessions` row, asserts `user_sessions` is gone within the same transaction. Document this in the test class docblock so the next person doesn't disable `dama` thinking the test needs it.

### Functional

- Login → `/account/sessions` shows one row.
- Revoke a non-current session → row gone from list, that browser's next request hits `/login`.
- "Revoke others" → keeps current, drops the rest, current row still shows post-action.
- CSRF rejection on missing/wrong token.
- Admin can revoke another user's session at `/admin/users/{id}/sessions/{sessId}/revoke`.
- Non-admin GET on `/admin/users/{otherUserId}/sessions` → 403.
- Self-revoke button is rendered `disabled` for the current row (HTML assertion).

Functional tests use the mock-file session storage (`when@test: storage_factory_id: session.storage.factory.mock_file`), so they don't exercise the trigger or the PdoSessionHandler — that's covered by the integration layer against the real test Postgres.

## Rollout and ops

### Deploy steps (first time)

1. Run migration — creates `user_sessions`, the trigger function, and the trigger.
2. `MAXMIND_LICENSE_KEY=<key> bin/console app:geoip:update` — populates `var/geoip/GeoLite2-Country.mmdb`.
3. No app restart needed. Existing logged-in sessions self-heal into `user_sessions` on next request via the lazy create-on-missing path.

### Compose / persistence

Confirm `var/geoip/` is included in the existing `var/` volume mount on the `php-fpm` and `worker` services. If `var/` is fully volume-mounted today, no change needed.

### Steady state

- Manual: re-run `bin/console app:geoip:update` monthly to refresh the MMDB. Acceptable to skip — `CountryResolver` returns null gracefully if the file is missing or stale.
- Automated monthly refresh is a separate follow-up ticket (TrueNAS cron or Messenger schedule).

### Runbook

- **User locked out / suspected compromise** — DBA can still `DELETE FROM sessions WHERE sess_id = '...'` directly; trigger keeps `user_sessions` consistent.
- **GeoLite2 attribution** — add "IP geolocation by MaxMind GeoLite2." to the page footer or `/legal` page (GeoLite2 EULA requirement).
- **Trigger broke after a Symfony upgrade** — if a future Symfony version renames the `sessions` table or `sess_id` column, the trigger silently no-ops on deletes. The integration trigger test catches this on the next CI run.

### Privacy

- IP, UA, country are stored only for currently-active sessions and purged on session expiry (cookie + GC lifetime is 30 days rolling). No long-term retention.
- Admin viewing of another user's IP/UA is scoped to `ROLE_ADMIN`; an audit-trail for admin actions is the subject of a separate ticket (#75).
- One-line note in the page header confirms the 30-day window for users.

## New composer dependencies

- `whichbrowser/parser` (UA parsing). MIT-licensed. ~6 MB of regex data lazy-loaded on first parse — parse happens once at session row creation, never on the hot path.
- `geoip2/geoip2` (MaxMind reader). Apache-2.0-licensed.

Both PHP 8.5-compatible as of January 2026; verify exact constraint solving on `composer require`.

## Decisions resolved (from issue's "Open decisions")

1. **UA parser library** — `whichbrowser/parser`.
2. **`last_seen_at` throttle window** — 60 seconds.
3. **Label constraints** — 64 chars, plain text, server-trimmed.
4. **MMDB shipping** — fetched on deploy via `app:geoip:update`, feature-flag-gated on `MAXMIND_LICENSE_KEY`.

Additional decisions made during this brainstorm:

5. **Rollout gap** — lazy create-on-missing in `kernel.request` listener; no backfill migration.
6. **Self-revoke** — revoke button rendered `disabled` for the current row; user signs out via the existing `/logout` link in the header.
7. **IP column type** — `VARCHAR(45)`, not Postgres `INET` (simpler Doctrine mapping; no querying need).
8. **Voter shape** — single `MANAGE` attribute with admin-bypass-then-ownership, not the `MANAGE_OWN` / `MANAGE_ANY` split sketched in the issue (matches `EventVoter` / `PhotoVoter`).

## Runbook

### var/geoip/ persistence

`compose.yaml` mounts `./:/app:delegated` on both the `php` (php-fpm) and `worker` services — this is a full project-root bind-mount, so `var/geoip/` is included transitively. No separate volume entry is needed. Container rebuilds (`docker compose build`, `docker compose up --build`) do **not** remove `var/geoip/` because bind-mounts are never touched by image rebuilds. Only `docker compose down -v` (with the `-v` flag, which only removes named volumes) or manual deletion of the host-side directory would lose the file.

### Sessions / GeoIP (issue #76)

- **First-time deploy:** set `MAXMIND_LICENSE_KEY` in env, run `bin/console app:geoip:update` once to populate `var/geoip/GeoLite2-Country.mmdb`. Without the MMDB, country flags are simply omitted from the sessions list — no errors.
- **Monthly refresh:** re-run `bin/console app:geoip:update`. Skipping it is fine; GeoLite2 ages gracefully and `CountryResolver` tolerates a stale or missing file.
- **Emergency session revoke:** `DELETE FROM sessions WHERE sess_id = '...'` via psql; the `on_sessions_delete` trigger cascades into `user_sessions`.
- **Footer attribution:** the line "IP geolocation by MaxMind GeoLite2." is the GeoLite2 EULA-required attribution; do not remove.

## Risks and mitigations

- **Trigger lives outside Doctrine's view.** Mitigation: integration test for the cascade. If the `sessions` table or `sess_id` column is ever renamed (Symfony upgrade), the test catches it.
- **GeoLite2 license requires attribution.** Mitigation: footer/legal-page line, called out in the runbook.
- **`last_seen_at` writes add load.** With 60s throttle, max ~60 writes/active-user/hour. For a low-traffic admin tool this is noise; if the system ever grows, the throttle window can be widened without schema change.
- **Composer dep weight.** `whichbrowser/parser` lazy-loads its regex data; first request after deploy may be slightly slower while the data is read from disk. Cached by OPcache thereafter.
