# Project-wide Form Theme Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a global Twig form theme (`templates/form/daisy.html.twig`) that auto-applies DaisyUI classes to inputs, labels, errors, and help text — and remove the three hand-rolled styling workarounds it replaces (inline `<style>` blocks on admin event + collection forms; per-row `attr/row_attr/label_attr` overrides on the reset-password templates).

**Architecture:** Extend Symfony's `form_div_layout.html.twig`. Override a small set of blocks (`form_row`, `form_label`, `form_widget_simple`, `textarea_widget`, `choice_widget_collapsed`, `form_help`, `form_errors`) to merge DaisyUI classes additively. Register globally via `twig.form_themes` in `config/packages/twig.yaml` — no per-template `{% form_theme %}` calls.

**Tech Stack:** Symfony 8 Twig Bridge, DaisyUI 5 / Tailwind, PHPUnit 13.

**Spec:** [`docs/superpowers/specs/2026-06-10-form-theme-design.md`](../specs/2026-06-10-form-theme-design.md)

---

## File map

**Create:**
- `templates/form/daisy.html.twig` — the form theme (overrides 7 blocks).

**Modify:**
- `config/packages/twig.yaml` — register the theme globally.
- `templates/reset_password/request.html.twig` — strip per-row override args.
- `templates/reset_password/reset.html.twig` — strip per-row override args (both password fields).
- `templates/admin/event/form.html.twig` — delete the `{% block stylesheets %}` override.
- `templates/admin/collection/form.html.twig` — delete the `{% block stylesheets %}` override.

No new tests. The existing PHPUnit suite already covers behavior across the affected surfaces (`LoginTest`, `PasswordResetTest`, `EventLogoUploadTest`, `EventQrTest`, `OwnershipScopingTest`, `PhotoModerationTest`, `PhotoUploadTest`). The theme changes presentation only — class names on existing elements — and the tests select by `name=""`, `data-testid=""`, or button label text. A green test suite after each task is the regression signal.

---

## Task 1: Create the form theme template and register it globally

**Files:**
- Create: `templates/form/daisy.html.twig`
- Modify: `config/packages/twig.yaml`

- [ ] **Step 0: Create the feature branch off main**

```bash
git fetch origin
git checkout main
git pull --ff-only origin main
git checkout -b feature/28-form-theme
```

Expected: clean switch to a fresh `feature/28-form-theme` branch based on the latest `origin/main`. The name satisfies the project's `^(feature|hotfix|bugfix|release)/\d+-` rule (enforced by GrumPHP on commit).

- [ ] **Step 1: Create `templates/form/daisy.html.twig`**

```twig
{%- extends 'form_div_layout.html.twig' -%}

{#-
    Project-wide DaisyUI form theme. Registered globally via twig.form_themes
    in config/packages/twig.yaml so every `form_row` call picks it up.

    Class-merge pattern: caller-supplied attr.class wins (we append, never
    replace), so {{ form_row(field, {attr: {class: 'input input-lg'}}) }}
    still works as an escape hatch.
-#}

{%- block form_row -%}
    <div class="form-control w-full">
        {{- form_label(form) -}}
        {{- form_widget(form) -}}
        {{- form_help(form) -}}
        {{- form_errors(form) -}}
    </div>
{%- endblock form_row -%}

{%- block form_label -%}
    {%- if label is not same as(false) -%}
        {%- set label_attr = label_attr|merge({class: (label_attr.class|default('') ~ ' label-text')|trim}) -%}
        {{- parent() -}}
    {%- endif -%}
{%- endblock form_label -%}

{%- block form_widget_simple -%}
    {%- set type = type|default('text') -%}
    {%- if type != 'hidden' -%}
        {%- set attr = attr|merge({class: (attr.class|default('') ~ ' input input-bordered w-full')|trim}) -%}
    {%- endif -%}
    {{- parent() -}}
{%- endblock form_widget_simple -%}

{%- block textarea_widget -%}
    {%- set attr = attr|merge({class: (attr.class|default('') ~ ' textarea textarea-bordered w-full')|trim}) -%}
    {{- parent() -}}
{%- endblock textarea_widget -%}

{%- block choice_widget_collapsed -%}
    {%- set attr = attr|merge({class: (attr.class|default('') ~ ' select select-bordered w-full')|trim}) -%}
    {{- parent() -}}
{%- endblock choice_widget_collapsed -%}

{%- block form_help -%}
    {%- if help is not empty -%}
        <small class="text-xs text-base-content/60">{{ help|trans(help_translation_parameters|default([]), translation_domain|default('messages')) }}</small>
    {%- endif -%}
{%- endblock form_help -%}

{%- block form_errors -%}
    {%- if errors|length > 0 -%}
        <ul class="text-error text-xs mt-1">
            {%- for error in errors -%}
                <li>{{ error.message }}</li>
            {%- endfor -%}
        </ul>
    {%- endif -%}
{%- endblock form_errors -%}
```

- [ ] **Step 2: Register the theme in `config/packages/twig.yaml`**

The current file is:

```yaml
twig:
    file_name_pattern: '*.twig'

when@test:
    twig:
        strict_variables: true
```

Replace its content with:

```yaml
twig:
    file_name_pattern: '*.twig'
    form_themes:
        - 'form/daisy.html.twig'

when@test:
    twig:
        strict_variables: true
```

- [ ] **Step 3: Verify Twig + container compile**

```bash
bin/console lint:twig templates/form/daisy.html.twig
bin/console cache:clear
```

Expected:
- `lint:twig` → `[OK] All 1 Twig files contain valid syntax.`
- `cache:clear` → `[OK] Cache for the "dev" environment ...`

- [ ] **Step 4: Run the full PHPUnit suite (regression check)**

```bash
vendor/bin/phpunit
```

Expected: all green (currently 85 tests, 228 assertions, no deprecations/notices/warnings). Field names, button labels, and `data-testid` selectors are unchanged — the theme only adds class names to inputs/labels.

If any test fails, do NOT proceed. Read the failure — most likely cause: a selector somewhere asserts a specific class name or wrapper structure. Fix the theme (don't fix the test) so the rendered structure stays compatible. If that's not possible, STOP and escalate.

- [ ] **Step 5: Commit**

```bash
git add templates/form/daisy.html.twig config/packages/twig.yaml
git commit -m "28 - add DaisyUI form theme and register globally"
```

---

## Task 2: Strip per-row `attr` overrides from reset-password templates

**Files:**
- Modify: `templates/reset_password/request.html.twig`
- Modify: `templates/reset_password/reset.html.twig`

These overrides were added during issue #17 as a workaround for the missing theme. Now that the theme exists, they're redundant.

- [ ] **Step 1: Simplify `templates/reset_password/request.html.twig`**

Find this block (lines 21-29 today):

```twig
                {{ form_start(requestForm, {attr: {class: 'space-y-3'}}) }}
                    {{ form_row(requestForm.email, {
                        label: 'Email',
                        label_attr: {class: 'label-text'},
                        row_attr: {class: 'form-control w-full'},
                        attr: {class: 'input input-bordered w-full'},
                    }) }}
                    <button type="submit" class="btn btn-primary w-full">Send reset link</button>
                {{ form_end(requestForm) }}
```

Replace it with:

```twig
                {{ form_start(requestForm, {attr: {class: 'space-y-3'}}) }}
                    {{ form_row(requestForm.email, {label: 'Email'}) }}
                    <button type="submit" class="btn btn-primary w-full">Send reset link</button>
                {{ form_end(requestForm) }}
```

`space-y-3` on the `<form>` stays — that's layout, not field styling.

- [ ] **Step 2: Simplify `templates/reset_password/reset.html.twig`**

Find this block (lines 11-23 today):

```twig
                {{ form_start(resetForm, {attr: {class: 'space-y-3'}}) }}
                    {{ form_row(resetForm.plainPassword.first, {
                        label_attr: {class: 'label-text'},
                        row_attr: {class: 'form-control w-full'},
                        attr: {class: 'input input-bordered w-full'},
                    }) }}
                    {{ form_row(resetForm.plainPassword.second, {
                        label_attr: {class: 'label-text'},
                        row_attr: {class: 'form-control w-full'},
                        attr: {class: 'input input-bordered w-full'},
                    }) }}
                    <button type="submit" class="btn btn-primary w-full">Update password</button>
                {{ form_end(resetForm) }}
```

Replace it with:

```twig
                {{ form_start(resetForm, {attr: {class: 'space-y-3'}}) }}
                    {{ form_row(resetForm.plainPassword.first) }}
                    {{ form_row(resetForm.plainPassword.second) }}
                    <button type="submit" class="btn btn-primary w-full">Update password</button>
                {{ form_end(resetForm) }}
```

- [ ] **Step 3: Re-lint the templates**

```bash
bin/console lint:twig templates/reset_password/
```

Expected: `[OK] All 4 Twig files contain valid syntax.`

- [ ] **Step 4: Run the reset-password test suite**

```bash
vendor/bin/phpunit tests/Functional/Security/PasswordResetTest.php
```

Expected: PASS — 7 tests, 38 assertions. Same as before the change; the form theme now supplies the classes the tests don't care about.

- [ ] **Step 5: Commit**

```bash
git add templates/reset_password/request.html.twig templates/reset_password/reset.html.twig
git commit -m "28 - reset-password: drop per-row attr overrides (form theme handles it)"
```

---

## Task 3: Remove the inline `<style>` block from the admin event form

**Files:**
- Modify: `templates/admin/event/form.html.twig`

The template currently overrides `{% block stylesheets %}` with a hand-rolled 25-line CSS stub that styles inputs/selects/textareas under `#event-form`. The form theme replaces all of it.

- [ ] **Step 1: Delete the `{% block stylesheets %}` override**

Remove the entire block starting at `{% block stylesheets %}` (currently line 48) and ending at the closing `{% endblock %}` (currently line 76). The deleted region is exactly:

```twig
{% block stylesheets %}
    {{ parent() }}
    <style>
        #event-form > div { display: grid; gap: 1rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        #event-form > div > div { display: flex; flex-direction: column; gap: 0.375rem; }
        #event-form label { font-size: 0.875rem; font-weight: 500; color: var(--color-base-content); }
        #event-form input[type="text"],
        #event-form input[type="email"],
        #event-form input[type="number"],
        #event-form input[type="date"],
        #event-form input[type="datetime-local"],
        #event-form textarea,
        #event-form select {
            width: 100%;
            border: 1px solid color-mix(in oklab, var(--color-base-content) 20%, transparent);
            border-radius: 0.5rem;
            padding: 0.5rem 0.75rem;
            background-color: var(--color-base-100);
            color: var(--color-base-content);
            font-size: 0.875rem;
        }
        #event-form textarea { min-height: 6rem; }
        #event-form .form-help, #event-form small { font-size: 0.75rem; color: color-mix(in oklab, var(--color-base-content) 60%, transparent); }
        #event-form ul { color: var(--color-error); font-size: 0.75rem; margin-top: 0.25rem; padding-left: 1rem; }
        @media (max-width: 1024px) {
            #event-form > div { grid-template-columns: 1fr; }
        }
    </style>
{% endblock %}
```

After the edit, the file ends at line 46 (the closing `{% endblock %}` of `admin_actions`).

- [ ] **Step 2: Re-lint**

```bash
bin/console lint:twig templates/admin/event/form.html.twig
```

Expected: `[OK] All 1 Twig files contain valid syntax.`

- [ ] **Step 3: Run admin event functional tests**

```bash
vendor/bin/phpunit tests/Functional/Admin/EventLogoUploadTest.php tests/Functional/Admin/EventQrTest.php tests/Functional/Admin/OwnershipScopingTest.php
```

Expected: all green. These tests cover the event create/edit pages; they assert on `name="event[field]"` and button text (`'Save'`, `'Create'`) which the theme doesn't affect.

- [ ] **Step 4: Commit**

```bash
git add templates/admin/event/form.html.twig
git commit -m "28 - admin event form: drop inline style block (form theme handles it)"
```

---

## Task 4: Remove the inline `<style>` block from the admin collection form

**Files:**
- Modify: `templates/admin/collection/form.html.twig`

Same workaround, same cleanup as Task 3, scoped to `#collection-form`.

- [ ] **Step 1: Delete the `{% block stylesheets %}` override**

Remove the entire block starting at `{% block stylesheets %}` (currently line 36) and ending at the closing `{% endblock %}` (currently line 61). The deleted region is exactly:

```twig
{% block stylesheets %}
    {{ parent() }}
    <style>
        #collection-form > div { display: grid; gap: 1rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        #collection-form > div > div { display: flex; flex-direction: column; gap: 0.375rem; }
        #collection-form label { font-size: 0.875rem; font-weight: 500; color: var(--color-base-content); }
        #collection-form input[type="text"],
        #collection-form input[type="email"],
        #collection-form textarea,
        #collection-form select {
            width: 100%;
            border: 1px solid color-mix(in oklab, var(--color-base-content) 20%, transparent);
            border-radius: 0.5rem;
            padding: 0.5rem 0.75rem;
            background-color: var(--color-base-100);
            color: var(--color-base-content);
            font-size: 0.875rem;
        }
        #collection-form textarea { min-height: 6rem; }
        #collection-form .form-help, #collection-form small { font-size: 0.75rem; color: color-mix(in oklab, var(--color-base-content) 60%, transparent); }
        #collection-form ul { color: var(--color-error); font-size: 0.75rem; margin-top: 0.25rem; padding-left: 1rem; }
        @media (max-width: 1024px) {
            #collection-form > div { grid-template-columns: 1fr; }
        }
    </style>
{% endblock %}
```

After the edit, the file ends at line 34 (closing `{% endblock %}` of `admin_actions`).

- [ ] **Step 2: Re-lint**

```bash
bin/console lint:twig templates/admin/collection/form.html.twig
```

Expected: `[OK] All 1 Twig files contain valid syntax.`

- [ ] **Step 3: Run the admin functional tests**

```bash
vendor/bin/phpunit tests/Functional/Admin/
```

Expected: all green. There's no dedicated collection-form test, but `OwnershipScopingTest` exercises collection routes and `EventQrTest` etc. exercise the event form — both surfaces the theme touches.

- [ ] **Step 4: Commit**

```bash
git add templates/admin/collection/form.html.twig
git commit -m "28 - admin collection form: drop inline style block (form theme handles it)"
```

---

## Task 5: Full quality gate + manual visual smoke

**Files:** none modified — verification only.

- [ ] **Step 1: Run the full GrumPHP suite**

```bash
vendor/bin/grumphp run
```

Expected: green across all tasks (`phpstan` level 10, `phpcs` PSR-12, `phpmnd`, `phpcpd`, `rector`, `securitychecker_roave`, `yamllint`, `doctrine:schema:validate`, `phpunit`).

If `yamllint` flags `config/packages/twig.yaml`, double-check the indentation of `form_themes` (4-space, list-of-strings).

- [ ] **Step 2: Run the full PHPUnit suite one more time**

```bash
vendor/bin/phpunit
```

Expected: same number of tests as before the change (currently 85), still green, no deprecations.

- [ ] **Step 3: Manual visual smoke — start the dev stack**

```bash
docker compose up -d
```

Wait until http://localhost:8080 responds.

- [ ] **Step 4: Visually verify each affected surface**

For each URL below, confirm: inputs are full-width with rounded DaisyUI borders, labels read clearly above the inputs, the page doesn't shift back to the pre-#17 unstyled look. There must be no console errors.

1. http://localhost:8080/login — should look IDENTICAL to before (not touched).
2. http://localhost:8080/reset-password — email input full-width, label "Email", button below.
3. http://localhost:8080/reset-password/reset/<any-token> — even with an invalid token, GET will redirect; instead browse via the `/reset-password` happy path. (Tip: trigger via Mailpit at http://localhost:8025.)
4. http://localhost:8080/admin/events/new — every field full-width, 2-column layout on `lg:` screens, no regression vs the pre-#28 look.
5. http://localhost:8080/admin/events/<id> — same as above, plus the logo display row is unchanged.
6. http://localhost:8080/admin/collections/new — every field full-width, 2-column on `lg:`.
7. http://localhost:8080/admin/collections/<id> — same.

If any surface regresses, the theme needs a fix — most likely the missing block is `choice_widget_expanded` (for radio/checkbox) or `vich_file_widget` (already handled by VichUploaderBundle). Investigate, patch the theme, re-commit, re-test.

- [ ] **Step 5: Open a PR**

```bash
git push -u origin feature/28-form-theme
gh pr create --title "28 - project-wide DaisyUI form theme" --body "$(cat <<'EOF'
## Summary
- Adds `templates/form/daisy.html.twig` and registers it globally via `twig.form_themes`.
- Removes the per-row `attr/row_attr/label_attr` overrides on `templates/reset_password/{request,reset}.html.twig` (added as a workaround during #17).
- Removes the hand-rolled `{% block stylesheets %}` CSS stubs on `templates/admin/event/form.html.twig` and `templates/admin/collection/form.html.twig`.
- Login template untouched — separate rewrite tracked in #30.

## Test plan
- [x] `vendor/bin/phpunit` — full green, no test changes needed
- [x] `vendor/bin/grumphp run` — full green
- [x] Manual visual smoke on `/login`, `/reset-password`, `/reset-password/reset/<token>`, `/admin/events/new`, `/admin/events/<id>`, `/admin/collections/new`, `/admin/collections/<id>`

Closes #28.

Spec: `docs/superpowers/specs/2026-06-10-form-theme-design.md`

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-review notes (for the executing agent)

- **Spec coverage:** Theme template → Task 1; global registration → Task 1; reset-password cleanup → Task 2; event form cleanup → Task 3; collection form cleanup → Task 4; full PHPUnit pass → Tasks 1+5; grumphp → Task 5; visual smoke → Task 5; PR → Task 5.
- **No placeholders:** every block of code/commands is concrete; no "TBD"/"TODO"/"similar to above".
- **Type consistency:** Block names used (`form_row`, `form_label`, `form_widget_simple`, `textarea_widget`, `choice_widget_collapsed`, `form_help`, `form_errors`) are Symfony's standard Twig form block names and are spelled identically wherever referenced.
- **Branch name:** `feature/28-form-theme` matches the project's `^(feature|hotfix|bugfix|release)/\d+-` rule.
- **Out-of-scope reminders:** Do NOT touch `templates/security/login.html.twig` — that's tracked in [#30](https://github.com/jwderoos/eventPhotos/issues/30). Do NOT add radio/checkbox blocks unless a smoke-test surface reveals a need.
