# Organizer-controlled event styling — Phase 1: colors

**Issue:** #54 (parent). This spec covers **phase 1 only**.
**Date:** 2026-07-01

## Context

Issue #54 asks for organizer-configurable branding of guest-facing surfaces
(colors, banner/hero image, typography) resolved through a three-tier override
chain (Event → EventCollection → Organizer), applied to the public event
landing, public photo gallery, and invitation emails.

The full issue is too large for one implementation. It is decomposed into four
sub-projects, each with its own spec → plan → implementation cycle:

| # | Sub-project | Status |
|---|---|---|
| **1** | **Styling foundation + colors** | **this spec** |
| 2 | Banner / hero image | later |
| 3 | Typography (self-hosted fonts) | later |
| 4 | Invitation-email branding | later |

Phase 1 builds the shared **three-tier resolution infrastructure** plus **colors**
as the first concrete tokens, applied to the public **landing** and **gallery**.
Banner, typography, and email branding are explicitly out of scope here but will
reuse the resolution chain and admin patterns established now.

## Decisions locked during brainstorming

- **Scope:** phase 1 = colors only, across all three tiers. Banner is phase 2.
- **Organizer tier lives in a new `OrganizerProfile` entity** (1:1 with `User`),
  keeping `User` lean (auth/identity only).
- **Token set:** exactly three colors — font, background, button — plus a glow
  toggle for the background.
- **Background:** a flat validated hex color plus a boolean **glow** toggle. When
  glow is on, the system auto-derives a soft radial gradient from the accent
  (button) color. Organizers never type raw CSS — no free-form CSS, no injection
  surface (the concern #54 explicitly excludes).
- **Inherit UX:** each field has a per-field **"override" checkbox**. Unchecked =
  inherit (stored `null`); checked = the picker value is stored.
- **Admin layout:** colors inline on the existing Event and EventCollection edit
  pages (matching the mockup); organizer defaults on the account settings area.
- **Shared fields** modeled as a Doctrine **embeddable**, not a trait.
- **Public wrapper** lives in `templates/public/_base.html.twig` so landing and
  gallery are styled by one change.

## Stack anchors (verified)

- Public surfaces use **DaisyUI v5** (`data-theme="silk"` on `public/_base.html.twig`),
  themed via CSS custom properties `--color-base-100`, `--color-base-content`,
  `--color-primary`, `--color-primary-content`. Resolved colors override these on
  a wrapper element; descendant `btn-primary` / `text-base-content` / `bg-base-100`
  inherit through the cascade. **No new CSS required.**
- **No CSP is configured** in the project (no nelmio, no `Content-Security-Policy`
  header). Relevant to phase 3 (fonts), not phase 1.
- Existing event **logo** upload (Vich + `event_logos_storage`) is the reference
  for the phase-2 banner path — not touched here.

---

## 1. Data model

### `StyleSettings` — Doctrine embeddable

A single embeddable holds the four nullable fields, reused by all three tiers:

```
StyleSettings (#[ORM\Embeddable])
  ?string fontColor        // '#RRGGBB' or null = inherit
  ?string backgroundColor  // '#RRGGBB' or null = inherit
  ?string buttonColor      // '#RRGGBB' or null = inherit
  ?bool   glowEnabled      // true / false / null = inherit
```

- Each color column: `#[ORM\Column(type: STRING, length: 7, nullable: true)]`.
- `glowEnabled`: `#[ORM\Column(type: BOOLEAN, nullable: true)]`.
- Validation on each color: `#[Assert\Regex('/^#[0-9a-fA-F]{6}$/')]` (nullable
  allowed — null means inherit).
- Immutable-ish value semantics: plain getters/setters; embeddables are always
  instantiated by Doctrine (never null themselves), individual fields nullable.
- Exposes a small helper `isEmpty(): bool` (all four fields null) for the admin
  "does this tier set anything" checks if needed.

### Embedding

`#[ORM\Embedded(class: StyleSettings::class, columnPrefix: 'style_')]` on:

- **`Event`** → adds `style_font_color`, `style_background_color`,
  `style_button_color`, `style_glow_enabled` to `events`.
- **`EventCollection`** → same columns on `event_collections`.
- **`OrganizerProfile`** (new) → same columns on `organizer_profiles`.

Each entity gets a `getStyle(): StyleSettings` accessor. The embedded object is
initialized in the constructor / on hydration so it is never null.

### `OrganizerProfile` — new entity

```
#[ORM\Entity]
#[ORM\Table(name: 'organizer_profiles')]
#[ORM\UniqueConstraint(columns: ['user_id'])]
class OrganizerProfile
  ?int    id
  User    user            // #[ORM\OneToOne], JoinColumn nullable:false, unique
  StyleSettings style      // #[ORM\Embedded(columnPrefix:'style_')]
```

- Created lazily: the account styling controller does
  `findOneBy(['user' => $user]) ?? new OrganizerProfile($user)` before binding
  the form, so profiles only exist for organizers who have opened the settings.
- Resolver looks it up via `OrganizerProfileRepository::findOneBy(['user' => $owner])`;
  a missing profile simply means "that tier contributes nothing." `User` gets no
  inverse relation, to keep it lean.

### Migration

Single `bin/console doctrine:migrations:diff` (never hand-written, per project
rule). It adds the `style_*` columns to `events` and `event_collections` and
creates `organizer_profiles`. All new columns nullable → every existing row is
all-null → resolves to system defaults. No data migration. `doctrine:schema:validate`
must be green.

---

## 2. Resolution

### `ResolvedStyle` — immutable value object

Every field concrete (no nulls). Constructed only by `StyleResolver`.

```
final class ResolvedStyle
  string fontColor
  string backgroundColor
  string buttonColor
  bool   glowEnabled
  // derived:
  string buttonContentColor()  // '#000000' | '#FFFFFF' by luminance of buttonColor
  string backgroundCss()       // flat hex, or radial-gradient(...) when glowEnabled
```

- **`buttonContentColor`** — relative luminance of `buttonColor`; returns white
  for dark buttons, black for light, so button label text stays legible.
- **`backgroundCss`** — when `glowEnabled` is false, returns `backgroundColor`.
  When true, returns
  `radial-gradient(circle, rgba(<r>,<g>,<b>,0.4), <backgroundColor>)` where
  `<r,g,b>` are parsed from `buttonColor` (the accent). This is the only place a
  gradient string is produced, and it is built from validated hex — organizers
  never supply it.

### System defaults

Constants (on `ResolvedStyle` or a dedicated `SystemStyleDefaults`) preserving the
current silk-theme appearance:

```
FONT       = '#1F2937'
BACKGROUND = '#FFFFFF'
BUTTON     = '#FF6B35'
GLOW       = false
```

(Exact hex values chosen to match the current public look; adjust only if the
silk theme differs, verified against `assets/styles/daisyui-theme.mjs`.)

### `StyleResolver` service

```
resolve(Event $event): ResolvedStyle
```

Walks, per field independently, first-non-null wins:

```
Event.style → Event.collection?.style → OrganizerProfile(owner)?.style → SYSTEM_DEFAULT
```

- `glowEnabled` resolves the same way (a nullable bool: null = inherit).
- Injects `OrganizerProfileRepository`. One lightweight query per resolve
  (page-level, cheap; no N+1 concern for a single event page).
- Also exposes `resolveInherited(Event $event): ResolvedStyle` (or a parameter)
  that resolves the chain **excluding the Event tier** — used by the admin form
  to display greyed inherited values on disabled inputs. Analogous methods for
  the collection and organizer edit contexts (each resolves the chain above its
  own tier).

---

## 3. Rendering

- `Public\EventController` landing + photos actions call
  `$this->styleResolver->resolve($event)` and pass `resolvedStyle` to the view.
- `templates/public/_base.html.twig` wraps the `public_main` block in a wrapper
  element that carries inline CSS-variable overrides **only when `resolvedStyle`
  is defined** (non-public pages that don't set it are unaffected):

```html
<div style="
  --color-base-content: {{ resolvedStyle.fontColor }};
  --color-base-100: {{ resolvedStyle.backgroundColor }};
  --color-primary: {{ resolvedStyle.buttonColor }};
  --color-primary-content: {{ resolvedStyle.buttonContentColor }};
  background: {{ resolvedStyle.backgroundCss }};
">
  {% block public_main %}{% endblock %}
</div>
```

- Twig `html_attr` escaping applies to the attribute; combined with the
  validated-hex invariant this is injection-safe. The `background` value is the
  only compound value and is system-generated from validated hex.
- Landing and gallery inherit the wrapper with no per-template changes.

---

## 4. Admin UI

### `StyleSettingsType` — reusable form (mapped to the embeddable)

Embedded via `->add('style', StyleSettingsType::class)` in `EventType`,
`EventCollectionType`, and the new `OrganizerProfileType`.

Per color/toggle field:
- an **unmapped "override" checkbox** (`custom_font_color`, etc.), and
- the value control (color input for colors; the glow control is the checkbox
  itself — "Enable background glow", override-gated the same way).

Form event wiring:
- **PRE_SET_DATA:** initialize each override checkbox from `value !== null`.
- **SUBMIT / POST_SUBMIT:** for each field whose override checkbox is unchecked,
  write `null` onto the embeddable (inherit); otherwise keep the submitted value.
- **Inherited display:** the form receives an `inherited` option (a
  `ResolvedStyle` of the parent chain, from `StyleResolver`) used to prefill the
  disabled inputs' shown value when override is off. Presentation only — never
  persisted.

### Placement

- **Event:** colors inline on the existing event edit page (`EventType`), laid out
  as a right-hand panel with the live preview (per mockup).
- **EventCollection:** colors inline on the collection edit page.
- **Organizer defaults:** a new section/form on the account settings area
  (`templates/account/show.html.twig` + a new account styling controller action),
  alongside display-name / mail config.

### Live preview — `style-preview` Stimulus controller

- Targets: the three color inputs, the override checkboxes, the glow checkbox,
  and a preview card.
- Mirrors the `ResolvedStyle` derivation **client-side** (glow gradient from the
  accent color, luminance-based button-content contrast) and live-updates the
  preview card's CSS variables + background on every `input` / `change`.
- When a field's override is off, the controller previews with that field's
  **inherited** value, supplied via a `data-style-preview-inherited-*` attribute
  rendered from the `inherited` `ResolvedStyle`.

---

## 5. Authorization

- **Event** styling edits ride the existing `EventVoter::EDIT` (same form, same
  controller gate — no new check).
- **EventCollection** styling edits ride the existing `EventCollectionVoter::EDIT`.
- **OrganizerProfile** edits are the current user's own → the account controller
  scopes strictly to `getUser()` (self-check); no cross-user access path exists,
  so no new voter is required. (If a shared edit path emerges later, revisit.)

State-changing POSTs use `isCsrfTokenValid` per existing controller conventions
(Symfony form CSRF covers the form submits).

---

## 6. Testing

- **Unit**
  - `StyleResolver`: per-field precedence — each of the four fields independently
    resolves Event → Collection → Organizer → default; missing OrganizerProfile
    tier skipped; all-null → all defaults.
  - `ResolvedStyle`: `buttonContentColor` luminance thresholds (dark vs light
    button); `backgroundCss` flat vs gradient; gradient RGB parsed from accent.
- **Integration**
  - `StyleSettingsType`: override-checkbox off → field persisted as `null`;
    on → submitted hex persisted; round-trip through the embeddable.
- **Functional**
  - Public landing and gallery render the resolved CSS-variable wrapper with the
    expected values for a styled event, and defaults for an unstyled one.
  - Event edit form and account styling form persist overrides; voter gating on
    the event/collection edit paths unchanged.
- **Gates:** PHPStan level 10 clean; `phpcs` PSR-12; `doctrine:schema:validate`
  green; no magic numbers (phpmnd) — luminance threshold / gradient alpha as named
  constants.

---

## 7. Out of scope (deferred to later phases)

- Banner / hero image upload, storage, derivatives (**phase 2**).
- Typography / self-hosted fonts + any CSP work (**phase 3**).
- Invitation-email branding — needs inlined styles, not CSS custom properties
  (**phase 4**).
- Styling of `/admin/**` surfaces and photo-serve endpoints (out of #54 entirely).
- Free-form custom CSS (permanently out — injection risk).

## Acceptance criteria (phase 1)

- [ ] Organizer can set font/background/button colors + glow on Event,
      EventCollection, and their own OrganizerProfile via `/admin`, each field
      independently overridable or inherited.
- [ ] Public event landing and gallery render with the resolved styling; an
      unstyled event renders the system defaults (visually unchanged from today).
- [ ] Resolution is per-field, most-specific-wins, across all three tiers with a
      system-default fallback.
- [ ] Live admin preview reflects the resolved styling (including inherited
      values and derived glow/contrast) as the organizer edits.
- [ ] Edits gated by `EventVoter::EDIT` / `EventCollectionVoter::EDIT`; organizer
      profile scoped to the current user.
- [ ] PHPStan level 10 clean, `doctrine:schema:validate` green, full suite passes.
