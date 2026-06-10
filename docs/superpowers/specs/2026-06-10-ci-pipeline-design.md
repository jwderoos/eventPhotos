# CI Pipeline — Design

**Issue:** [#22 — CI pipeline (GitHub Actions)](https://github.com/jwderoos/eventPhotos/issues/22)
**Status:** Design approved, ready for implementation plan.

## Goal

Run the project's existing quality gates (GrumPHP suite + PHPUnit) on every pull request and on every push to `main`, against a real Postgres 16, so that broken code or broken migrations cannot land on the default branch.

## Why now

The foundation document marks "CI pipeline" as a deferred item. The project currently has 26 PHPUnit tests, PHPStan level 10, and a full GrumPHP suite that all pass locally — but nothing enforces this on merge. As soon as a second person or a future-self forgets to run `vendor/bin/grumphp run` before pushing, regressions land silently.

## Architecture

Single workflow file at `.github/workflows/ci.yml` with one job named `quality`. The job spins up a Postgres 16 service container, installs PHP 8.5 + extensions, creates the test database, applies migrations, validates the entity/schema sync, and runs GrumPHP.

### Triggers

- `pull_request` — any branch into any branch.
- `push` to `main` — catches direct pushes / post-merge verification, gives the default branch a green checkmark.

### Concurrency

```yaml
concurrency:
  group: ci-${{ github.ref }}
  cancel-in-progress: ${{ github.event_name == 'pull_request' }}
```

Force-pushing to a PR cancels the in-flight run. `main` runs are never cancelled.

### Runner + services

- `ubuntu-latest`.
- Postgres 16 as a service container, exposed on `127.0.0.1:5432`, with `POSTGRES_USER=postgres`, `POSTGRES_PASSWORD=postgres`, `POSTGRES_DB=app`. Health-checked via `pg_isready` so the job doesn't start hitting the DB before it's ready.

The `_test` suffix is appended automatically by `config/packages/doctrine.yaml`'s `when@test` block, so the actual test DB is `app_test`.

## Step list (inside the `quality` job)

1. **Checkout** — `actions/checkout@v4`.
2. **Setup PHP** — `shivammathur/setup-php@v2` with `php-version: 8.5` and `extensions: ctype, dom, exif, fileinfo, gd, iconv, intl, mbstring, pdo, pdo_pgsql, simplexml, tokenizer, zip, opcache`. Coverage off. `tools: composer:v2`.
3. **Composer cache** — `actions/cache@v4` keyed on `${{ runner.os }}-composer-${{ hashFiles('composer.lock') }}`.
4. **Install deps** — `composer install --no-interaction --no-progress --prefer-dist`.
5. **Create test DB + migrate** — `bin/console doctrine:database:create --env=test --if-not-exists` then `bin/console doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration`.
6. **Schema/migration drift check** — `bin/console doctrine:schema:validate --env=test`. Fails if entity mapping is invalid OR if the migrated DB schema doesn't match the entity metadata (i.e., someone added a field and forgot the migration).
7. **Install importmap vendor assets** — `bin/console importmap:install`. AssetMapper populates `assets/vendor/` from `importmap.php`. Required for any test that renders a template using `importmap()`. Should ideally run as a composer `post-install-cmd` (tracked in issue #26); until then, explicit here.
8. **Build Tailwind CSS** — `bin/console tailwind:build`. Generates the `tailwindcss` asset that `templates/base.html.twig` references via the `styles/app.css` import. Any functional test that renders the layout will 500 without this.
9. **GrumPHP** — `vendor/bin/grumphp run --tasks=composer,file_size,phpcpd,phpcs,phpmnd,phpstan,phpunit,rector,securitychecker_roave,yamllint`.

### Why the explicit `--tasks` allowlist

GrumPHP's full task list includes two tasks that don't apply in CI:

- `git_branch_name` — whitelists `feature/N-...` / `hotfix/N-...` etc. and blacklists `main`. Would fail on every push to `main` and on the merge-commit ref pull_request events use.
- `git_commit_message` — merge commits are tolerated by its matcher, but skipping it is cleaner than relying on the regex covering every edge case.

The `shell` task (see below) is also excluded — the full schema validate in step 6 already covers it.

### Environment

Workflow-level `env:` block:

```yaml
env:
  DATABASE_URL: "postgresql://postgres:postgres@127.0.0.1:5432/app?serverVersion=16&charset=utf8"
```

## `grumphp.yml` change

Add one task — runs on every local commit, gives early feedback that entity attributes are valid without requiring a fully-migrated dev DB:

```yaml
shell:
    scripts:
        - ['-c', 'bin/console doctrine:schema:validate --skip-sync --no-interaction']
```

`--skip-sync` validates entity mapping only, not the DB schema. CI runs the full `doctrine:schema:validate` (step 6) which covers both mapping and sync.

**Note on the script syntax:** GrumPHP's `Shell` task `exec()`s `/bin/sh` and passes the array entries as positional args to `sh`. The literal argv form (`['bin/console', 'doctrine:schema:validate', ...]`) does not work — `sh` treats the first arg as a script filename. The canonical form is `['-c', '<full command string>']`.

**Note on DB connection:** In practice `--skip-sync` does need a reachable DB to bootstrap Doctrine's command. This is acceptable for the local hook because devs run Postgres locally for development anyway. CI runs the full mapping+sync check (step 6) on a freshly-migrated DB.

## Failure handling + caching

- Any step fails → job fails → PR check goes red. No `continue-on-error`.
- No artifact uploads, no retries.
- Composer cache only. No PHPStan/Rector cache — small wins at this codebase size, easy to add later.

## Explicitly NOT in scope

These are deliberate exclusions, called out so future-us knows they were considered:

- **Branch protection rules.** Configured in GitHub repo settings, not in the workflow yaml. A manual follow-up after the workflow is green.
- **Coverage reporting / badges** (Codecov etc.).
- **Deploy step.**
- **PHP version matrix.** `composer.json` pins `^8.5`; only one version is supported.
- **`.phpunit.cache` cross-run caching.** Negligible payoff for a 26-test suite.

(An earlier draft of this spec listed "Tailwind build" as out of scope on the assumption that tests don't need built CSS. That was wrong — `base.html.twig` references the `tailwindcss` asset and Symfony's `asset()` throws when it's missing, so every functional test that renders the layout fails without the build. The build step is now included; see step 8.)

## Files touched

- `.github/workflows/ci.yml` — new.
- `grumphp.yml` — add `shell` task.

## Open questions

None blocking. The `--skip-sync` DB-connection question above is the only thing that may surface during implementation; the design covers all three fallback options.

## Implementation outcomes

What actually shipped on PR #25, captured here so the next reader sees the final shape:

- **`grumphp.yml` shell-task syntax landed as `['-c', '<command>']`**, not the argv form the original draft showed. See the `grumphp.yml change` section.
- **Two extra CI steps** (`importmap:install`, `tailwind:build`) were added during iteration when the test suite failed against the layout template. Documented in the step list above.
- **First CI run caught a real schema drift** — the messenger-table migration shipped in #13 used a Doctrine-version-mismatched index name. Fixed in-place on the same PR. This was the entire reason for the `doctrine:schema:validate` step existing, and it paid for itself on the very first green build.
- **Composer auto-scripts gap** — `composer.json` defines `auto-scripts` but doesn't wire them to `post-install-cmd`/`post-update-cmd`, which is why the explicit `importmap:install` step exists in CI. Tracked in issue #26; once fixed, the explicit step can be removed.
