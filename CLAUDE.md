# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack

PHP 8.5 / Symfony 8 / Doctrine ORM 3 + DBAL 4 / PostgreSQL 16. Asset Mapper + Stimulus + Turbo + Tailwind (symfonycasts/tailwind-bundle, no Webpack). Flysystem for storage. Symfony Messenger (Doctrine transport) for async work. Vich Uploader for the event-logo upload. PHP attributes everywhere — no annotations.

## Commands

Run PHP / Composer / `bin/console` / `vendor/bin/*` on the **host** (the user has matching PHP 8.5 via Homebrew). Only use `docker compose` for the runtime stack (php-fpm, nginx, Postgres, Mailpit, worker, tailwind watcher).

```bash
# Stack
docker compose up -d                              # app on http://localhost:8080
docker compose logs -f worker                     # tail async photo worker
docker compose restart worker                     # after handler/entity changes

# DB / migrations (host)
bin/console doctrine:migrations:migrate
bin/console doctrine:schema:validate              # mapping + sync — also a GrumPHP gate
bin/console doctrine:database:create --env=test --if-not-exists

# Users
bin/console app:create-user <email> <displayName> <password> [ROLE_ORGANIZER|ROLE_ADMIN]

# Messenger
bin/console messenger:failed:show
bin/console messenger:failed:retry <id>

# Tests
vendor/bin/phpunit                                # full suite
vendor/bin/phpunit tests/Unit/Entity/PhotoTest.php
vendor/bin/phpunit --filter testMarkReady

# Quality (these run in CI via GrumPHP; can run individually)
vendor/bin/grumphp run                            # everything
vendor/bin/phpstan analyse                        # level 10 across src, tests, public
vendor/bin/rector process --dry-run
vendor/bin/phpcs                                  # PSR-12
```

## CI / branching gates (GrumPHP — enforced locally on commit AND in CI)

- Claude should create a branch from main when starting work that will change/create files on disk
- Branch name must match `^(feature|hotfix|bugfix|release)/\d+-`. `main` / `develop` / `master` are blacklisted for direct commits.
- Commit messages must contain a GitHub issue number (or be a merge from one of the prefixed branches).
- `phpstan` level 10, `phpcs` PSR-12, `phpmnd` (no magic numbers in `src/`), `phpcpd` (50-line / 100-token duplication), `rector`, `securitychecker_roave`, `phpunit` (the full suite), and `doctrine:schema:validate` all gate commits.
- CI workflow (`.github/workflows/ci.yml`) spins up Postgres 16, runs migrations against the test DB, then `grumphp run` with the same task list.
- The `phpunit` gate is schema-deterministic: `App\Tests\Bootstrap\SchemaRebuildExtension` (registered in `phpunit.xml`) drops + recreates the `_test` database from migrations once, before the first test, so the schema always matches the code under test regardless of what branch last touched the local DB. `dama/doctrine-test-bundle` then wraps each test in a transaction. Set `SKIP_SCHEMA_REBUILD=1` to skip the rebuild for tight local `--filter` loops (GrumPHP/CI never set it). It lives in a PHPUnit extension, not `tests/bootstrap.php`, so process-isolated tests (`#[RunTestsInSeparateProcesses]`) don't re-fire it in a child process. See #116.

## Migrations

- **Never hand-write a migration.** Generate via `bin/console doctrine:migrations:diff`, then edit only the `getDescription()` text if needed. Don't author `CREATE TABLE`, `CREATE INDEX`, etc. by hand.
- Reason: hand-written index/constraint names drift from Doctrine ORM's auto-generated hash names (algorithm differs by Doctrine version). Issue #13 shipped a hand-written messenger-table migration whose index name was wrong; the bug was invisible until CI started running `doctrine:schema:validate` on a freshly-migrated DB.
- The drift gate is two-tier: GrumPHP's `shell` task runs `doctrine:schema:validate --skip-sync` locally on every commit (mapping-only, no DB needed), and CI runs the full `doctrine:schema:validate --env=test` after migrating against a fresh Postgres.

## Architecture

### Photo ingest pipeline (the core flow)

1. **Upload** — `POST /admin/events/{id}/photos` (`Admin\PhotoController::upload`) validates (JPEG only, ≤10 MB), computes a SHA-256 `contentHash`, dedupes on `(event_id, content_hash)` (unique constraint), persists a `Photo` in `PhotoStatus::Pending` to get an id, then writes the original to the `photo_originals_storage` Flysystem disk at `event-<eventId>/<photoId>.jpg`. On success it dispatches `App\Message\ProcessPhoto` to the `async` transport and returns `202`.
2. **Worker** — `messenger:consume async failed` (the `worker` compose service, time-limit 3600 s / memory-limit 128 MB, `restart: unless-stopped`) picks up the message.
3. **Handler** — `MessageHandler\ProcessPhotoHandler` is idempotent: it no-ops unless `status === Pending`. It stages the original to a tmp file (because `exif_read_data` needs a real path, not a Flysystem stream), reads EXIF via `Service\Photo\ExifReader` using the event's timezone, then `Service\Photo\DerivativeGenerator` writes thumb + preview to their respective storages. On success `Photo::markReady($takenAt, $width, $height)`; domain-rejection (`PhotoRejected`) → `markFailed`.
4. **Retry** — `Admin\PhotoController::retry` (#115) resets a single `Failed` photo to `Pending` via `Photo::resetForRetry()` and re-dispatches `ProcessPhoto` (fresh ingest, `reingest: false`, so the window guard re-applies). It is **gated on `Event::isRetainOriginals()`**: a failed photo's original is deleted at failure time unless originals are retained (see `ProcessPhotoHandler`'s `maybeDeleteOriginal` in the `catch (PhotoRejected)` path), so with originals off there is nothing to retry from — the route redirects with a flash error, and the row shows no Retry button (only the visible ingest-error message, as a troubleshooting handhold). Re-dispatching is safe because the handler is idempotent. The Messenger transport itself retries 3× (1s base, ×5 multiplier) before routing to the `failed` Doctrine queue. Note that `Failed` status is only reachable via `PhotoRejected` (missing/unparseable EXIF, or outside the ingest window) — deterministic rejections, so a bare retry re-fails unless the organizer first changes the window/timezone.
5. **Serving** — `Public\PhotoServeController` streams thumbs/previews via `/p/{id}/thumb.jpg` and `/p/{id}/preview.jpg` with a one-year immutable `Cache-Control` and a SHA-1 ETag derived from `id|updatedAt`. Originals are **never** web-served.

Storage paths (all under `var/uploads/`, local Flysystem adapters configured in `config/packages/flysystem.yaml`):
- `photo_originals_storage` → `var/uploads/photos/originals/event-<id>/<photoId>.jpg`
- `photo_thumbs_storage` → `var/uploads/photos/thumbs/...`
- `photo_previews_storage` → `var/uploads/photos/previews/...`
- `event_logos_storage` → `var/uploads/event-logos/` (Vich-managed, on the `Event` entity)
- `event_banners_storage` → `var/uploads/event-banners/event-<id>.jpg` (public event hero; single normalized JPEG derivative synchronously generated on upload, no original kept; served via `public_event_banner`)

When injecting a specific storage, use `#[Autowire(service: 'photo_originals_storage')] FilesystemOperator $originals` — there are six `FilesystemOperator` services, so plain autowiring is ambiguous.

### Domain model

- `Event` (`events` table, unique `slug`) owns `Photo`s via cascade delete. Has optional `startsAt`/`endsAt`, a `defaultWindowMinutes` (defaults to `Event::DEFAULT_WINDOW_MINUTES = 30`), and a `timezone` used when parsing EXIF.
- `EventCollection` groups events.
- `Photo` is a state machine: `PhotoStatus::Pending → Ready` or `Pending → Failed → (resetForRetry) → Pending`. Transitions throw `DomainException` on illegal moves — don't bypass `markReady`/`markFailed`/`resetForRetry`.
- `User` with role hierarchy `ROLE_ADMIN ⊃ ROLE_ORGANIZER ⊃ ROLE_USER`.

### Security

Form-login firewall (`/login`), entity user provider on `User.email`. Access control: `/admin/**` requires `ROLE_ORGANIZER`, everything else `PUBLIC_ACCESS`. Authorization is done via voters (`EventVoter`, `EventCollectionVoter`, `PhotoVoter`) — pattern is *admin bypass, otherwise ownership check*. Controllers should call `$this->denyAccessUnlessGranted(EventVoter::EDIT, $event)` (use the constants, not raw strings) and use `$this->isCsrfTokenValid(...)` for state-changing POSTs.

### Sessions

Sessions live in Postgres via Symfony's `PdoSessionHandler`. The handler is built by `App\Session\PdoSessionHandlerFactory` (pulls the PDO out of the existing Doctrine connection), registered as the public service `session.handler.pdo` in `config/services.yaml`, and selected via `framework.session.handler_id` in `config/packages/framework.yaml`. Cookie + GC lifetime is 30 days, rolling: every session-touching response refreshes the cookie's expiry (`AbstractSessionListener` emits `Set-Cookie` whenever the session is non-empty). The `sessions` table is NOT a Doctrine entity, so `config/packages/doctrine.yaml` carries `schema_filter: ~^(?!sessions$).*~` to keep `doctrine:schema:validate` green — anyone adding a Doctrine entity literally named `sessions` will need to update that filter. The `when@test:` block keeps `storage_factory_id: session.storage.factory.mock_file`, so functional tests never touch the real table. Anonymous public routes (photo serve, lightbox, QR) do not touch the session, so they do not create rows — verified by audit recorded with #68 (re-run `grep -rn "addFlash\|getSession\|csrf_token" src/Controller/Public/ templates/public/` if in doubt before adding new public flows). Revoke a session with `DELETE FROM sessions WHERE sess_id = '…'`.

### Federated identity (Google SSO)

Google login is wired via `knpuniversity/oauth2-client-bundle` (Google client). All `/oauth/google/*` routes are gated by `App\Service\Auth\GoogleOAuthFeatureFlag::isEnabled()` — empty `GOOGLE_OAUTH_CLIENT_ID` → routes 404 + no UI. One redirect URI per environment, `/oauth/google/callback`, handled by `App\Controller\OAuth\OAuthDispatcherController` which redirects to the per-purpose callback based on a session-stashed `oauth_google_purpose`. Login resolution lives in `App\Service\Auth\IdentityLinker`; invite redemption via Google in `App\Service\Auth\IdentityCreator` (PESSIMISTIC_WRITE transaction mirroring the password redemption). Identities are stored in `user_identities`, unique on `(provider, subject)` and `(user_id, provider)`. Tests bind `App\Tests\Fake\FakeGoogleOAuthClient` in `config/services.yaml` under `when@test:` — no real network. Operational prerequisites and per-env Google Cloud Console setup are in `docs/superpowers/specs/2026-06-12-19-google-sso-design.md`.

### Per-organizer mail transport

Organizers can configure their own SMTP transport (encrypted at rest with libsodium `crypto_secretbox`, keyed by `MAIL_CONFIG_ENCRYPTION_KEY` — 32 raw bytes shipped via the `%env(base64:...)%` processor). `App\Service\Mail\OrganizerMailerResolver::forEvent($event)` / `forUser($user)` return the organizer's verified mailer or **throw `OrganizerMailNotConfiguredException`** — there is no platform-mail fallback (changed in #77). Event-scoped senders inject the resolver and call `$resolver->forEvent($event)->send($email)`, setting an explicit `->from()` from the organizer's `UserMailConfig::getSenderAddress()`. Platform-level flows (invitations, password reset) keep using the autowired `MailerInterface` — they have no event context. `App\Service\Mail\DsnValidator` gates persistence: scheme must be `smtp`/`smtps`, host must resolve via `DnsResolver` to public IPs only (RFC1918 / loopback / link-local / multicast / reserved rejected). `OrganizerMailerResolver` (live event sends) and `TransportBuilder` (verification sends) both build transports through `App\Service\Mail\PinnedTransportFactory`, which resolves the host, requires **every** resolved IP to pass `App\Service\Mail\PublicIpInspector` (a positive-allowlist classifier that decodes IPv4-mapped/6to4/Teredo IPv6 and rejects CGNAT), then connects to the literal validated IP while passing the original hostname as the TLS `peer_name`. This closes the validate-then-reconnect (DNS rebinding) SSRF: the stored `verified` flag is never trusted at connect time. On a live send, a verified transport that now resolves to a non-public address is hard-failed (Messenger retry/dead-letter) and the config is auto-unverified (#87). The resolver hard-fails (no silent fallback) when a verified transport throws at send time — Messenger handles retries/dead-letter; a `\SodiumException` from corrupted ciphertext is logged and re-thrown as `OrganizerMailNotConfiguredException` (no platform fallback). The resolver returns a `RenderingMailer` built via `TransportBuilder` (so resolver-sent `TemplatedEmail` bodies render in both test and prod). The visitor "photos are live" notification (#77) uses this resolver exclusively for both the confirmation and announcement emails, gated on `isCustomActive($owner)`; events carry a one-shot `publishedAt` + `notificationsEnabled` flag, and `EventNotificationSubscription` holds the double-opt-in state machine. `PinnedTransportFactory` injects `mailer.transport_factory` (the container `Transport` service) and builds via `fromDsnObject()` — NOT the static `Transport::fromDsn`, which doesn't consult container-tagged factories and would break the test-only `App\Tests\Mail\InMemoryTransportFactory` interception. Both `OrganizerMailerResolver` and `TransportBuilder` delegate transport construction to `PinnedTransportFactory`. `TransportBuilder` wraps its mailer in `App\Service\Mail\RenderingMailer` so `TemplatedEmail` bodies render via the Twig `BodyRenderer` (intentionally bypassing the global mailer pipeline — no MessageBus, no MessageDataCollector — so the per-DSN path stays distinguishable from the platform default in functional tests). Operational prerequisites and per-env key handling are in `docs/superpowers/specs/2026-06-17-78-organizer-mail-transport-design.md`.

### Controllers

Split into `App\Controller\Admin\*` (behind `/admin`, organizer-gated) and `App\Controller\Public\*`. Both extend `AbstractController`. All routes use `#[Route]` attributes — no YAML routing beyond `config/routes.yaml` glob.

### Tests

Organized as `tests/Unit/`, `tests/Integration/`, `tests/Functional/`. PHPUnit 13 is configured with `failOnDeprecation`, `failOnNotice`, `failOnWarning` all `true` and `restrictWarnings/Notices` on the source — a single deprecation in the code path will fail the test. `dama/doctrine-test-bundle` is available for transactional integration tests. Test DB name gets a `_test` suffix via `dbname_suffix` in `when@test`.

### Commits

Claude will not do commits. After finishing work, always propose a single line commit message
