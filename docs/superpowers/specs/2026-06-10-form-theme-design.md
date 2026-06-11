# Project-wide Twig Form Theme — Design

**Issue:** [#28 — Project-wide Twig form theme for DaisyUI styling](https://github.com/jwderoos/eventPhotos/issues/28)
**Status:** Design approved, ready for implementation plan.

## Goal

Add a single Twig form theme that auto-applies DaisyUI classes to inputs, labels, errors, help text, and row wrappers — so `{{ form_row(field) }}` produces styled markup with zero per-row overrides. Register globally so every form in the project benefits automatically.

## Why now

After issue #17 shipped, the project has two diverging patterns for styling Symfony Forms:

1. **`templates/admin/event/form.html.twig`** carries a 25-line inline `<style>` block that hand-targets `#event-form input[type="text"]`, `#event-form textarea`, `#event-form select` etc. This block re-implements styling that the rest of the app expresses via DaisyUI utility classes.
2. **`templates/reset_password/{request,reset}.html.twig`** pass `attr / row_attr / label_attr` overrides to every `form_row` call to inject `input input-bordered w-full`, `form-control w-full`, and `label-text`.

Neither is the right place for this. The third diverging case — login — bypasses Symfony Forms entirely; rewriting it is tracked separately as a low-priority follow-up ([#30](https://github.com/jwderoos/eventPhotos/issues/30)) and is **out of scope** for this issue.

A project-wide theme makes form rendering uniform and removes both hand-rolled paths.

## Architecture

A single template at `templates/form/daisy.html.twig` extends Symfony's default `form_div_layout.html.twig` and overrides a small set of blocks. Registered globally via `twig.form_themes` in `config/packages/twig.yaml`. No per-template `{% form_theme %}` calls.

```yaml
# config/packages/twig.yaml
twig:
    file_name_pattern: '*.twig'
    form_themes: ['form/daisy.html.twig']
```

### Blocks overridden

| Block | Behavior |
|---|---|
| `form_row` | Wraps `form_label` + `form_widget` + `form_help` + `form_errors` in `<div class="form-control w-full">`. |
| `form_label` | Merges `label-text` into `label_attr.class`, then calls `parent()`. |
| `form_widget_simple` | Merges `input input-bordered w-full` into `attr.class`. Covers `TextType`, `EmailType`, `PasswordType`, `IntegerType`, and the `single_text` variants of `DateType` / `DateTimeType`. |
| `textarea_widget` | Merges `textarea textarea-bordered w-full`. |
| `choice_widget_collapsed` | Merges `select select-bordered w-full`. Covers `ChoiceType` and `EntityType` rendered as a select. |
| `form_help` | Renders as `<small class="text-xs text-base-content/60">{{ help }}</small>`. |
| `form_errors` | Renders as `<ul class="text-error text-xs mt-1"><li>…</li></ul>` per error. |

Hidden inputs (the CSRF token) are handled by `hidden_row`, which `form_div_layout` already renders without a wrapper — `parent()` preserves that behavior, so the theme does not need to override it.

### Class-merge pattern

All widget overrides use the additive pattern:

```twig
{%- set attr = attr|merge({class: (attr.class|default('') ~ ' input input-bordered w-full')|trim}) -%}
```

If a caller passes `attr.class` explicitly (e.g., `{{ form_row(field, {attr: {class: 'input input-lg'}}) }}`), the override is *appended* to ours rather than replacing it. This preserves an escape hatch for rare cases where a row needs custom sizing or coloring.

### Out of scope for the theme template

- `VichFileType` widget — VichUploaderBundle ships its own widget template that's already used for `Event.logoFile`. The form theme leaves it alone.
- Radio / checkbox widgets (`checkbox_widget`, `radio_widget`, `choice_widget_expanded`) — no `CheckboxType`, `RadioType`, or expanded `ChoiceType` is in use anywhere in the project today. YAGNI; add when needed.
- Inline-error rendering (errors next to the widget rather than below). DaisyUI patterns put errors below; matches Symfony's default position.

## Cleanup pass

The theme is only half the work. The other half is removing the workarounds it replaces:

1. **`templates/admin/event/form.html.twig`** — delete the `{% block stylesheets %}{{ parent() }}<style>…</style>{% endblock %}` override at the bottom. The 2-column layout (`lg:grid-cols-2` on the `.card-body`) continues to work because each row becomes a self-contained `<div class="form-control w-full">` that the grid container places into cells.
2. **`templates/admin/collection/form.html.twig`** — delete its `{% block stylesheets %}` override too (a near-clone of the event form's, scoped to `#collection-form`). Same reasoning applies.
3. **`templates/reset_password/request.html.twig`** — strip `attr` / `row_attr` / `label_attr` keys from the `form_row` call. The line collapses to `{{ form_row(requestForm.email, {label: 'Email'}) }}`.
4. **`templates/reset_password/reset.html.twig`** — same revert on both `plainPassword.first` and `plainPassword.second` rows.

The `space-y-3` class on `<form>` (added during issue #17 to space rows out) stays — that's layout, not field styling, and the theme doesn't address it.

## What's NOT changed

- **`templates/security/login.html.twig`** — hand-written `<input>` markup, no `form_row` calls. Tracked separately as [#30](https://github.com/jwderoos/eventPhotos/issues/30). Touching it here would either expand the blast radius (Symfony's `form_login` firewall depends on exact field names `_username` / `_password` / `_csrf_token` — a rewrite needs a `LoginFormType` and careful field-name configuration) or yield no benefit.
- **VichFileType styling** — leave VichUploaderBundle's template alone.
- **The grid layout on the event form** — `lg:grid-cols-2` stays in the template; that's a per-page layout decision, not a row-wrapper concern.

## Testing

- **Functional regression**: re-run the full PHPUnit suite. The theme adds DaisyUI class names to existing inputs/labels/wrappers but does not change field `name` attributes, button labels, or `data-testid` selectors. Existing tests select by `name="..."` (e.g., `request_password_reset_form[email]`) and by button text (`'Send reset link'`, `'Sign in'`, `'Update password'`, `'Save'`, `'Create'`) — all unaffected.
- **No new automated test for the theme itself.** It's pure presentation; the existing functional tests verify the structural surface the rest of the app relies on, and a unit test of "does Twig render this widget with that class" adds friction without catching real bugs.
- **Manual visual smoke** (record in PR description):
  - `/login` — should look identical to current (not touched).
  - `/reset-password` — full-width email box, label above, "Send reset link" button.
  - `/reset-password/reset/{token}` — two full-width password fields.
  - `/admin/events/new` and `/admin/events/{id}` — every input/textarea/select full-width with DaisyUI borders, two-column layout preserved on `lg:` screens.
  - `/admin/collections/new` and `/admin/collections/{id}` — same.

## Acceptance criteria

- `templates/form/daisy.html.twig` exists and is registered in `config/packages/twig.yaml`.
- All `attr / row_attr / label_attr` overrides removed from `templates/reset_password/{request,reset}.html.twig`.
- The `{% block stylesheets %}` overrides removed from `templates/admin/event/form.html.twig` and `templates/admin/collection/form.html.twig`.
- `vendor/bin/phpunit` is green with no test changes.
- `vendor/bin/grumphp run` is green.
- Manual visual smoke confirms no regressions on the five surfaces listed above.
