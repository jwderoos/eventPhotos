# Frontend & admin theming — design

**Date:** 2026-06-09
**Status:** Draft for review
**Scope:** Introduce a CSS framework, apply two distinct themes (public site and admin), and polish the admin layout.

## Goal

Replace the current hand-rolled CSS (one `body { background: skyblue }` rule and a handful of ad-hoc class names) with a real design system, while giving the public site (`/`) and the admin (`/admin`) visually distinct looks driven by a single framework.

Today the admin and public templates share `base.html.twig` and a scattered vocabulary of classes (`admin-nav`, `btn-primary`, `flash`, `home`). There is no CSS framework, no theme system, and no way to evolve either surface without hunting through templates.

## Non-goals

- Public marketing redesign (hero, features, footer expansion). Out of scope; a separate brainstorm later.
- Custom-brand themes with project-specific colors and typography. Deferred — the design is structured so this is a single-file swap when ready.
- Dark-mode toggle.
- Logo or wordmark asset. Staying text-only.
- Photo ingest UI changes. Separate feature.

## Framework choice

**Tailwind CSS v4 + daisyUI v5.**

Considered Bootstrap-everywhere, Tailwind+daisyUI everywhere, and a split (Bootstrap admin / Tailwind public). daisyUI was chosen because it is the only single-framework option that delivers genuinely distinct looks for the two surfaces — its first-class `data-theme` switching is the mechanism for "two themes, one stylesheet". daisyUI gives Bootstrap-equivalent component classes (`btn`, `card`, `table`, `modal`, `form-control`) for admin density; raw Tailwind utilities give the public side full flexibility to escape component patterns where needed.

Bootstrap-only was rejected because its DNA shows through public-facing pages no matter how much overriding happens. A two-framework split was rejected because it doubles the cognitive load for a solo developer.

## Toolchain

- `symfonycasts/tailwind-bundle` for the build. It manages Tailwind's standalone binary (no Node, no npm, no Vite) and integrates with AssetMapper via `php bin/console tailwind:build` and `--watch`.
- Existing AssetMapper + importmap + Stimulus stack stays unchanged. The bundle writes a built `app.css` that AssetMapper serves.
- Watcher runs as a sidecar service in `docker-compose` (alongside the existing `php` and `nginx` services) during development.
- CI runs `tailwind:build` once before any test that renders HTML/CSS.

## Theme strategy

Both themes compile into a single `app.css`. The active theme is selected at runtime by the `data-theme` attribute on `<html>`.

**Template layout split:**

- `templates/base.html.twig` — minimal `<html>`/`<head>`/`<body>` shell. No theme, no chrome. Exposes a `{% block html_attributes %}{% endblock %}` placeholder on the `<html>` tag.
- `templates/public/_base.html.twig` (new) — extends `base.html.twig`, sets `data-theme="winter"`, provides minimal public-side header (text wordmark "Event Photos") and footer.
- `templates/admin/_base.html.twig` (rewrite) — extends `base.html.twig`, sets `data-theme="corporate"`, provides the admin shell (drawer + navbar, breadcrumb, flashes, sticky action bar).
- `templates/security/login.html.twig` — extends the admin `_base` (login leads into admin, so it carries the admin theme).

**Starting themes:** daisyUI's built-in `corporate` for admin, `winter` for public. Chosen for fast time-to-coherent without locking the brand identity. The daisyUI plugin block in `assets/styles/app.css` is the single point of swap when the project commits to custom themes — change the `@plugin "daisyui"` config there, no template changes required.

**One bundle, both themes.** Each visitor downloads color tokens they will never see. Cost is ~4 KB per theme compressed — acceptable. The alternative (two separate CSS bundles per area) adds pipeline complexity without meaningful payoff.

## Admin layout

- **Shell:** daisyUI `drawer` component — CSS-only collapsible sidebar (uses a hidden checkbox, no JS). Open by default at `lg` and above; collapses to a hamburger on smaller screens.
- **Top bar:** daisyUI `navbar` inside the drawer's content side. Holds the hamburger (visible when the drawer is collapsed), a breadcrumb area, and a right-side user dropdown with display name and sign-out.
- **Sidebar contents:**
  - Brand strip: text-only "Event Photos · Admin"
  - daisyUI `menu` with Dashboard / Events / Collections
  - Active item highlighted via `active` class set from the current route prefix
- **Breadcrumbs:** daisyUI `breadcrumbs` in the top bar, fed by an `{% block admin_breadcrumb %}{% endblock %}` per page. Sensible default in the base (section name only).
- **Flash messages:** daisyUI `alert` component. Symfony flash label mapping: `success` → `alert-success`, `error`/`danger` → `alert-error`, `warning` → `alert-warning`, everything else → `alert-info`. Rendered above `{% block admin_main %}`.
- **Page container:** `max-w-7xl` centered, with consistent vertical padding (`py-8`). Lists and tables fill the container width.
- **Form pages:** two-column at `lg`+ (form on the left, optional sidebar tips on the right), single column below. Sticky bottom action bar rendered when `{% block admin_actions %}{% endblock %}` is defined; contains submit and cancel buttons.
- **List pages:** daisyUI `table` with `table-zebra`. Each row has explicit Edit (`btn btn-sm btn-ghost`) and Delete (`btn btn-sm btn-ghost text-error`, posts a CSRF-protected form with `confirm()` guard) buttons in an Actions column. Empty state is a centered text block with a CTA button to create the first item.

## Template migration map

Per file, classified as **rewrite** (markup substantially changes), **light edit** (block boundaries or class names only), or **new**.

**Layout**

| File | Change |
| --- | --- |
| `templates/base.html.twig` | light edit — add `html_attributes` block |
| `templates/public/_base.html.twig` | new |
| `templates/admin/_base.html.twig` | rewrite — drawer + navbar + sticky-action-bar shell |

**Admin pages** (extend new admin `_base`)

| File | Change |
| --- | --- |
| `templates/admin/dashboard.html.twig` | rewrite — daisyUI `stats` for counters, `card`s for panels |
| `templates/admin/event/index.html.twig` | rewrite — zebra table with edit/delete actions, empty state, "New event" CTA |
| `templates/admin/event/form.html.twig` | rewrite — `form-control` form (handles both new and edit via `mode` variable), sticky action bar |
| `templates/admin/collection/index.html.twig` | rewrite — same shape as event index |
| `templates/admin/collection/form.html.twig` | rewrite — same shape as event form |

**Public pages** (extend new public `_base`)

| File | Change |
| --- | --- |
| `templates/public/home.html.twig` | light edit — container + typography only, no redesign |
| `templates/public/event/landing.html.twig` | rewrite — `card` for details, daisyUI buttons, keeps existing Stimulus wiring |
| `templates/public/event/photos.html.twig` | rewrite — typography upgrade, ghost-button back link, styled empty state |

**Security**

| File | Change |
| --- | --- |
| `templates/security/login.html.twig` | rewrite — centered `card` on neutral background, admin theme |

**Assets**

| File | Change |
| --- | --- |
| `assets/styles/app.css` | rewrite — `@import "tailwindcss"` + `@plugin "daisyui"` with `corporate` and `winter` |
| `assets/controllers/` | unchanged — existing `share` controller keeps working |

## Testing

- Existing PHPUnit tests assert on routes and rendered text content; they should pass unchanged.
- One known test couples to a CSS class: `tests/Functional/Security/LoginTest.php` asserts `assertSelectorTextContains('.error', ...)`. The login template's error block becomes a daisyUI `alert alert-error`; the fix is to add `data-testid="login-error"` to the error element and update the assertion selector, following the pattern already in `EventPhotosStubTest`.
- No new tests for visual styling — CSS is not unit-testable. Verification is manual: load every admin and public route in a browser, confirm the right theme is active, confirm sticky action bar behaves on long forms, confirm tables render empty states.
- `tailwind:build` runs in the functional-test setup so compiled CSS exists when tests render pages.

## Risks

- **Tailwind v4 / daisyUI v5 are recent releases.** Both stable, but ecosystem search results skew toward older majors. Expect ~30 minutes of re-reading docs when behavior diverges from older tutorials.
- **`symfonycasts/tailwind-bundle` downloads the Tailwind standalone binary on first install.** A CDN outage during CI would fail the build. Mitigation: cache the binary in the docker image.
- **`btn-primary` class-name collision** between the current hand-rolled class and daisyUI's. Semantics differ. Approach: grep all call sites and rewrite them; do not try to preserve old definitions alongside daisyUI's.
- **One CSS bundle for both themes.** Cost is small (~4 KB compressed per unused theme) and the simplicity wins. Documented to avoid revisiting.

## Rollout

Single PR. The change is end-to-end: install bundle, write `app.css`, rewrite layouts, rewrite pages. Splitting would leave half-styled pages in `main`, which is worse than a larger PR. Verification before merge: open every admin and public route in a browser, confirm correct theme and no broken templates.
