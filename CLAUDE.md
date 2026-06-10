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

- Branch name must match `^(feature|hotfix|bugfix|release)/\d+-`. `main` / `develop` / `master` are blacklisted for direct commits.
- Commit messages must contain a GitHub issue number (or be a merge from one of the prefixed branches).
- `phpstan` level 10, `phpcs` PSR-12, `phpmnd` (no magic numbers in `src/`), `phpcpd` (50-line / 100-token duplication), `rector`, `securitychecker_roave`, and `doctrine:schema:validate` all gate commits.
- CI workflow (`.github/workflows/ci.yml`) spins up Postgres 16, runs migrations against the test DB, then `grumphp run` with the same task list.

## Architecture

### Photo ingest pipeline (the core flow)

1. **Upload** — `POST /admin/events/{id}/photos` (`Admin\PhotoController::upload`) validates (JPEG only, ≤25 MB), computes a SHA-256 `contentHash`, dedupes on `(event_id, content_hash)` (unique constraint), persists a `Photo` in `PhotoStatus::Pending` to get an id, then writes the original to the `photo_originals_storage` Flysystem disk at `event-<eventId>/<photoId>.jpg`. On success it dispatches `App\Message\ProcessPhoto` to the `async` transport and returns `202`.
2. **Worker** — `messenger:consume async failed` (the `worker` compose service, time-limit 3600 s / memory-limit 128 MB, `restart: unless-stopped`) picks up the message.
3. **Handler** — `MessageHandler\ProcessPhotoHandler` is idempotent: it no-ops unless `status === Pending`. It stages the original to a tmp file (because `exif_read_data` needs a real path, not a Flysystem stream), reads EXIF via `Service\Photo\ExifReader` using the event's timezone, then `Service\Photo\DerivativeGenerator` writes thumb + preview to their respective storages. On success `Photo::markReady($takenAt, $width, $height)`; domain-rejection (`PhotoRejected`) → `markFailed`.
4. **Retry** — `Admin\PhotoController::retry` only resets `Failed → Pending`; re-dispatching is always safe because the handler is idempotent. The Messenger transport itself retries 3× (1s base, ×5 multiplier) before routing to the `failed` Doctrine queue.
5. **Serving** — `Public\PhotoServeController` streams thumbs/previews via `/p/{id}/thumb.jpg` and `/p/{id}/preview.jpg` with a one-year immutable `Cache-Control` and a SHA-1 ETag derived from `id|updatedAt`. Originals are **never** web-served.

Storage paths (all under `var/uploads/`, local Flysystem adapters configured in `config/packages/flysystem.yaml`):
- `photo_originals_storage` → `var/uploads/photos/originals/event-<id>/<photoId>.jpg`
- `photo_thumbs_storage` → `var/uploads/photos/thumbs/...`
- `photo_previews_storage` → `var/uploads/photos/previews/...`
- `event_logos_storage` → `var/uploads/event-logos/` (Vich-managed, on the `Event` entity)

When injecting a specific storage, use `#[Autowire(service: 'photo_originals_storage')] FilesystemOperator $originals` — there are four `FilesystemOperator` services, so plain autowiring is ambiguous.

### Domain model

- `Event` (`events` table, unique `slug`) owns `Photo`s via cascade delete. Has optional `startsAt`/`endsAt`, a `defaultWindowMinutes` (defaults to `Event::DEFAULT_WINDOW_MINUTES = 30`), and a `timezone` used when parsing EXIF.
- `EventCollection` groups events.
- `Photo` is a state machine: `PhotoStatus::Pending → Ready` or `Pending → Failed → (resetForRetry) → Pending`. Transitions throw `DomainException` on illegal moves — don't bypass `markReady`/`markFailed`/`resetForRetry`.
- `User` with role hierarchy `ROLE_ADMIN ⊃ ROLE_ORGANIZER ⊃ ROLE_USER`.

### Security

Form-login firewall (`/login`), entity user provider on `User.email`. Access control: `/admin/**` requires `ROLE_ORGANIZER`, everything else `PUBLIC_ACCESS`. Authorization is done via voters (`EventVoter`, `EventCollectionVoter`, `PhotoVoter`) — pattern is *admin bypass, otherwise ownership check*. Controllers should call `$this->denyAccessUnlessGranted(EventVoter::EDIT, $event)` (use the constants, not raw strings) and use `$this->isCsrfTokenValid(...)` for state-changing POSTs.

### Controllers

Split into `App\Controller\Admin\*` (behind `/admin`, organizer-gated) and `App\Controller\Public\*`. Both extend `AbstractController`. All routes use `#[Route]` attributes — no YAML routing beyond `config/routes.yaml` glob.

### Tests

Organized as `tests/Unit/`, `tests/Integration/`, `tests/Functional/`. PHPUnit 13 is configured with `failOnDeprecation`, `failOnNotice`, `failOnWarning` all `true` and `restrictWarnings/Notices` on the source — a single deprecation in the code path will fail the test. `dama/doctrine-test-bundle` is available for transactional integration tests. Test DB name gets a `_test` suffix via `dbname_suffix` in `when@test`.
