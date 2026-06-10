# CI Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a GitHub Actions workflow that runs the project's quality gates (GrumPHP + PHPUnit + Doctrine schema validation) against a real Postgres 16 on every PR and every push to `main`.

**Architecture:** Single workflow file `.github/workflows/ci.yml` with one `quality` job using a Postgres 16 service container. GrumPHP gets one new `shell` task that runs `doctrine:schema:validate --skip-sync` on every local commit (mapping-only check, no DB sync).

**Tech Stack:** GitHub Actions, `shivammathur/setup-php@v2`, Postgres 16 service container, GrumPHP, PHPUnit, Doctrine ORM 3.

**Spec:** `docs/superpowers/specs/2026-06-10-ci-pipeline-design.md`

**Workflow note:** This project's git hook denies `git commit` from the agent — the agent stages files and the user commits. Plan reflects that.

---

### Task 1: Create feature branch + add `shell` task to `grumphp.yml`

**Files:**
- Modify: `grumphp.yml` (add new `shell` task block under `grumphp.tasks:`)

- [ ] **Step 1: Create the feature branch**

GrumPHP's `git_branch_name` task whitelists `feature/N-...`. Create the branch before any commits:

```bash
git checkout -b feature/22-ci-pipeline
```

Expected: `Switched to a new branch 'feature/22-ci-pipeline'`.

- [ ] **Step 2: Add the `shell` task to `grumphp.yml`**

Edit `grumphp.yml`. Add this block at the end of `grumphp.tasks:`, after the existing `yamllint:` block (keeping it at the bottom groups it with the other auxiliary tasks):

```yaml
        shell:
            scripts:
                - ['-c', 'bin/console doctrine:schema:validate --skip-sync --no-interaction']
```

Indentation: 8 spaces for the task key (`shell:`), 12 spaces for `scripts:`, 16 spaces for the script entry — matches the surrounding tasks.

**Note on the script syntax:** GrumPHP's `Shell` task `exec()`s `/bin/sh` and passes the array entries as positional args to `sh`. The literal argv form (`['bin/console', 'doctrine:schema:validate', ...]`) does not work — `sh` interprets the first arg as a script filename. Use `['-c', '<full command string>']`.

- [ ] **Step 3: Verify the `shell` task runs cleanly**

Run:

```bash
vendor/bin/grumphp run --tasks=shell
```

Expected: Task executes, exits 0. Output should include the Doctrine validate command running.

**Fallback per spec:** If the command fails with a database connection error (e.g. `SQLSTATE[08006]` or "could not find driver"), the `--skip-sync` flag is not sufficient for fully offline operation. Options:
1. **Accept the requirement** — devs usually have a running DB anyway. Proceed.
2. **Drop the local task** — revert `grumphp.yml`, rely on CI-only validation. Stop here and skip to Task 2.

Pick option 1 unless the user objects.

- [ ] **Step 4: Verify the full GrumPHP suite still passes**

Run:

```bash
vendor/bin/grumphp run
```

Expected: All tasks pass green, including the new `shell` task.

- [ ] **Step 5: Stage and request commit**

```bash
git add grumphp.yml
```

Tell the user: "Staged `grumphp.yml` with the new `shell` task. Please commit when ready (suggested message: `22 - add doctrine schema validate to grumphp`)."

Wait for the user to commit before moving to Task 2.

---

### Task 2: Create the GitHub Actions workflow file

**Files:**
- Create: `.github/workflows/ci.yml`

- [ ] **Step 1: Create the workflows directory**

```bash
mkdir -p .github/workflows
```

Expected: Directory created (or already exists — no error either way).

- [ ] **Step 2: Write `.github/workflows/ci.yml`**

Full file content:

```yaml
name: CI

on:
    pull_request:
    push:
        branches: [main]

concurrency:
    group: ci-${{ github.ref }}
    cancel-in-progress: ${{ github.event_name == 'pull_request' }}

jobs:
    quality:
        name: Quality
        runs-on: ubuntu-latest

        services:
            postgres:
                image: postgres:16
                env:
                    POSTGRES_USER: postgres
                    POSTGRES_PASSWORD: postgres
                    POSTGRES_DB: app
                ports:
                    - 5432:5432
                options: >-
                    --health-cmd "pg_isready -U postgres"
                    --health-interval 5s
                    --health-timeout 5s
                    --health-retries 10

        env:
            DATABASE_URL: "postgresql://postgres:postgres@127.0.0.1:5432/app?serverVersion=16&charset=utf8"

        steps:
            - name: Checkout
              uses: actions/checkout@v4

            - name: Setup PHP 8.5
              uses: shivammathur/setup-php@v2
              with:
                  php-version: '8.5'
                  extensions: ctype, dom, exif, fileinfo, gd, iconv, intl, mbstring, pdo, pdo_pgsql, simplexml, tokenizer, zip, opcache
                  coverage: none
                  tools: composer:v2

            - name: Get composer cache directory
              id: composer-cache
              run: echo "dir=$(composer config cache-files-dir)" >> "$GITHUB_OUTPUT"

            - name: Composer cache
              uses: actions/cache@v4
              with:
                  path: ${{ steps.composer-cache.outputs.dir }}
                  key: ${{ runner.os }}-composer-${{ hashFiles('**/composer.lock') }}
                  restore-keys: ${{ runner.os }}-composer-

            - name: Install dependencies
              run: composer install --no-interaction --no-progress --prefer-dist

            - name: Create test database
              run: bin/console doctrine:database:create --env=test --if-not-exists

            - name: Run migrations
              run: bin/console doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration

            - name: Validate schema (mapping + sync)
              run: bin/console doctrine:schema:validate --env=test

            - name: Install importmap vendor assets
              run: bin/console importmap:install

            - name: Build Tailwind CSS
              run: bin/console tailwind:build

            - name: GrumPHP
              run: vendor/bin/grumphp run --tasks=composer,file_size,phpcpd,phpcs,phpmnd,phpstan,phpunit,rector,securitychecker_roave,yamllint
```

**Why `importmap:install` and `tailwind:build`:** every functional test that renders `templates/base.html.twig` resolves the `styles/app.css` asset and the importmap'd `app` entrypoint. Without these two steps the test suite hard-fails on `Twig\Error\RuntimeError` ("vendor asset is missing" / "tailwindcss asset not found"). The importmap step is a CI-only workaround until composer's `auto-scripts` are wired to `post-install-cmd` (tracked in issue #26).

Indentation: 4 spaces throughout (matches project convention — `grumphp.yml` uses 4-space).

- [ ] **Step 3: Validate the new YAML**

Run:

```bash
vendor/bin/grumphp run --tasks=yamllint
```

Expected: Passes. (grumphp's `yamllint` task scans the repo for YAML and will pick up the new file.)

- [ ] **Step 4: Stage and request commit**

```bash
git add .github/workflows/ci.yml
```

Tell the user: "Staged `.github/workflows/ci.yml`. Please commit when ready (suggested message: `22 - add GitHub Actions CI workflow`)."

Wait for the user to commit before moving to Task 3.

---

### Task 3: Push, watch the CI run, iterate to green

**Files:**
- None changed (verification task).

- [ ] **Step 1: Push the branch**

```bash
git push -u origin feature/22-ci-pipeline
```

Expected: Branch published; GitHub returns a URL for opening a PR. (We open the PR after the run is green.)

- [ ] **Step 2: Watch the CI run**

The push triggers a workflow run automatically. Watch it:

```bash
gh run watch
```

If `gh run watch` reports no in-progress run, list the latest run for this branch and watch it explicitly:

```bash
gh run list --branch feature/22-ci-pipeline --limit 1
gh run watch <RUN_ID>
```

Expected: Final state `success` — all eleven steps green.

- [ ] **Step 3: Diagnose and iterate on failures**

If the run fails, identify the failing step and apply the right remediation. Common failure modes:

- **`Setup PHP 8.5` fails with "Could not setup PHP 8.5"**: `shivammathur/setup-php` may not have a stable PHP 8.5 release yet. Check the action's release notes. Mitigation in order of preference:
    1. Try `php-version: '8.5'` with `tools: composer:v2` (current default) — already specified.
    2. Try the nightly channel: `php-version: 'nightly'` — installs latest PHP `master`.
    3. Stop and consult the user — downgrading composer's PHP constraint is a real decision, not a workaround.

- **`Create test database` fails with permission denied**: the `postgres` superuser already has `CREATEDB`, so this should not happen. If it does, check the service `env:` block — `POSTGRES_USER: postgres` must match the `DATABASE_URL`.

- **`Run migrations` fails**: read the migration error. If a migration is genuinely broken on a fresh DB, fix the migration on the feature branch.

- **`Validate schema (mapping + sync)` fails on sync drift**: there is a real mismatch between entities and migrations. Locally: `bin/console doctrine:schema:validate --env=test` to see the diff, then `bin/console doctrine:migrations:diff` to generate the missing migration. Commit the migration on the feature branch and push.

- **`GrumPHP` task fails — `phpunit`**: a real test failure. Re-run locally: `vendor/bin/phpunit`. Fix on the feature branch.

- **`GrumPHP` task fails — `phpstan` / `rector` / `phpcs`**: re-run the specific failing task locally: `vendor/bin/grumphp run --tasks=phpstan` (or rector, etc.). Fix and push.

- **Anything else**: open the failing run in the GitHub UI (`gh run view <RUN_ID> --web`), read the step logs, fix, push, re-run `gh run watch`.

After every fix-and-push, re-run Step 2 to watch the new run. Loop until green.

- [ ] **Step 4: Open the PR**

Once the run is green:

```bash
gh pr create --title "22 - CI pipeline (GitHub Actions)" --body "$(cat <<'EOF'
Closes #22.

Adds a GitHub Actions workflow that runs GrumPHP + PHPUnit + Doctrine schema validation against Postgres 16 on every PR and every push to main.

See spec: \`docs/superpowers/specs/2026-06-10-ci-pipeline-design.md\`.
EOF
)"
```

Expected: PR URL returned.

- [ ] **Step 5: Verify the PR's check is green**

```bash
gh pr checks
```

Expected: `Quality` check appears and is green.

---

## Out-of-scope follow-ups

After the workflow is merged, the user can (manually, outside this plan) configure GitHub branch protection on `main` to require the `Quality` check before merging. Not in this plan because branch protection lives in GitHub repo settings, not in the workflow yaml.

---

## What actually happened (PR #25)

Recorded after execution so the next agent sees the full shape, not just the planned shape.

**Plan adjustments:**

- **Task 1 `shell` task syntax:** the literal argv form (`['bin/console', ...]`) does not work with GrumPHP's `Shell` task — `sh` interprets the first array entry as a script filename. Shipped form: `['-c', '<full command string>']`. Plan has been updated; spec footnote added.
- **Task 2 `ci.yml` content:** two additional steps (`importmap:install`, `tailwind:build`) were added between `Validate schema` and `GrumPHP`. The spec missed these because it assumed tests didn't need built assets. Step list above now reflects what shipped.

**Iteration log (Task 3):**

1. **CI run #1 — failed at `Validate schema`.** Schema validate caught a real drift: `migrations/Version20260610151632.php` (messenger table, shipped in #13) created the index with a Doctrine-version-mismatched name (`IDX_…BA31DB` instead of `IDX_…BA31DBBF396750`). The drift gate paid for itself on the very first run. Fix: edit the original migration in-place (project is pre-production, single dev, fixing-the-source preferred over band-aid migration). Drop+recreate local test DB.
2. **CI run #2 — failed at `GrumPHP / phpunit`** with `Twig\Error\RuntimeError: Unable to find asset "tailwindcss"`. Tests rendering the layout failed because Tailwind hadn't been built. Fix: add `bin/console tailwind:build` step.
3. **CI run #3 — failed at `GrumPHP / phpunit`** with `Twig\Error\RuntimeError: The "@hotwired/stimulus" vendor asset is missing`. AssetMapper's `importmap()` couldn't resolve vendor assets because `assets/vendor/` was empty. Root cause: `composer.json` defines `auto-scripts` but doesn't wire them to `post-install-cmd` / `post-update-cmd`. Workaround: add `bin/console importmap:install` step. Real fix tracked in issue #26.
4. **CI run #4 — green.** PR #25 merged.

**Out-of-band changes that landed on the same branch:**

- `CLAUDE.md` was created on the branch (initial file by the user, plus a "Migrations" section added by the agent codifying the rule "never hand-write migrations — always use `doctrine:migrations:diff`" — the rule that, had it existed, would have prevented the messenger-table index name bug).

**Issues created during execution:**

- **#26** — Wire composer `auto-scripts` to `post-install-cmd` / `post-update-cmd`. Removes the need for the explicit `importmap:install` step in CI.
