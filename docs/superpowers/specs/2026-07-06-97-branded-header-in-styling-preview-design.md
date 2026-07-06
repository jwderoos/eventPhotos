# #97 — Branded header in the styling preview

## Summary

The live "Preview" card in the styling section of the event and collection admin
forms currently renders only a bare `<h3>Preview</h3>` and a `Meld je aan`
button. Organizers pick colors expecting to see how their branded public page will
actually look, but the preview omits the single most identity-defining element —
the brand header (logo + label). This change renders a **miniature of the branded
public header** inside the preview card, restyled live by the existing
`style-preview` Stimulus controller, so organizers can judge how their logo/label
read against their chosen font color, background color, and glow.

Related: #96 (organizer brand in public header), #54 (organizer event styling).

## Decisions (locked)

- **Brand source: the owner's brand.** Resolve the brand from the event/collection
  **owner**, not the logged-in user. This matches how inherited *style* is already
  resolved (`StyleResolver::profileStyleFor($owner)`), so the preview stays
  consistent when an admin edits another organizer's event/collection.
- **Keep the `powered by: EventPhotos by JWdR` sub-line** — the preview is a
  faithful miniature of the real header.
- **Static, not clickable.** The brand in the preview is a styling mockup; it must
  not be an `<a>` that could navigate away from an unsaved form.

## Logo URL sourcing (the one non-trivial bit)

The real public header serves the logo via `public_event_brand_logo`, which is
keyed by an **event slug**. The collection form and a not-yet-saved new event have
no slug, so we need a slug-independent, owner-correct URL.

Two existing routes cover every case with **no new serving route** and **no slug
dependency**:

- `account_brand_logo` — serves the **current** user's brand logo (`getUser()`).
- `admin_user_brand_logo` (`/admin/users/{id}/brand-logo`) — serves a target
  user's logo, gated by `UserVoter::VIEW`, which requires **ROLE_ADMIN**.

Because `EventVoter::EDIT` / `EventCollectionVoter::EDIT` are "admin-bypass,
otherwise ownership", **a non-owner who reaches an edit form is necessarily an
admin**. New events/collections are always created with `owner = getUser()`. So the
logo-URL rule collapses to a clean two-way branch:

| Case | Condition | Logo URL |
|------|-----------|----------|
| Own event/collection (any role), or new | `owner.id === currentUser.id` | `account_brand_logo` |
| Editing another owner's (⇒ editor is admin) | otherwise | `admin_user_brand_logo(owner.id)` |

The `else` branch is only ever generated for an admin, and `UserVoter::VIEW` grants
admins access to any user, so the subsequent image request authorizes. A plain
organizer never reaches the `else` branch. URL *generation* is unguarded; the image
*request* is the thing that is authorized, and it always will be.

## Components

### 1. `App\Service\Brand\BrandPreview` (new)

`final readonly` value object — the preview-safe brand data handed to the template.

```php
final readonly class BrandPreview
{
    public function __construct(
        public ?string $label,
        public ?string $logoUrl,   // null when the brand has no logo
    ) {
    }
}
```

### 2. `App\Service\Brand\BrandResolver` (refactor)

Extract owner-based resolution so it can be reused without an `Event`:

```php
public function resolveForOwner(User $owner): ?ResolvedBrand
{
    $profile = $this->profiles->findOneBy(['user' => $owner]);
    if ($profile === null || !$profile->hasBrand()) {
        return null;
    }
    return new ResolvedBrand(
        label:   $profile->getBrandLabel(),
        hasLogo: $profile->getBrandLogoFilename() !== null,
        url:     $profile->getBrandUrl(),
    );
}

public function resolve(Event $event): ?ResolvedBrand
{
    return $this->resolveForOwner($event->getOwner());
}
```

No behavior change to the existing `resolve(Event)` callers.

### 3. `App\Service\Brand\BrandPreviewResolver` (new)

`final readonly` service. Deps: `BrandResolver`, `UrlGeneratorInterface`,
`Security`.

```php
public function forOwner(User $owner): ?BrandPreview
{
    $brand = $this->brands->resolveForOwner($owner);
    if ($brand === null) {
        return null;                       // → default-header fallback
    }

    $logoUrl = null;
    if ($brand->hasLogo) {
        $current = $this->security->getUser();
        $logoUrl = ($current instanceof User && $current->getId() === $owner->getId())
            ? $this->urls->generate('account_brand_logo')
            : $this->urls->generate('admin_user_brand_logo', ['id' => $owner->getId()]);
    }

    return new BrandPreview(label: $brand->label, logoUrl: $logoUrl);
}
```

Returns `null` (not an empty `BrandPreview`) when the owner has no brand, so the
template can cleanly branch to the default header.

### 4. Controller wiring

In `App\Controller\Admin\EventController::new` / `::edit` and
`App\Controller\Admin\EventCollectionController::new` / `::edit`, resolve the
preview from the owner and pass it into the template render array:

```php
'brandPreview' => $this->brandPreview->forOwner($owner),
```

- `EventController::new`  → owner is `$user` (`getUser()`).
- `EventController::edit`  → owner is `$event->getOwner()`.
- `EventCollectionController::new` → owner is `$user`.
- `EventCollectionController::edit` → owner is `$collection->getOwner()`.

### 5. Template `templates/_partials/_style_fields.html.twig`

Add an optional param `brandPreview` (defaults to `null` — the partial is included
with `only`). Render a compact **static** header **inside** the
`data-style-preview-target="card"` element, above `<h3>Preview</h3>`, so the
Stimulus controller keeps restyling it:

```twig
<div data-style-preview-target="card" data-theme="silk" class="...">
    <div class="card-body items-center text-center gap-3">
        {# miniature of templates/public/_base.html.twig's header — static #}
        <div class="flex flex-col items-center gap-0.5">
            {% if brandPreview is defined and brandPreview %}
                <span class="flex items-center gap-2">
                    {% if brandPreview.logoUrl %}
                        <img src="{{ brandPreview.logoUrl }}"
                             alt="{{ brandPreview.label ?? 'Brand logo' }}"
                             class="h-6 w-auto object-contain" />
                    {% endif %}
                    {% if brandPreview.label %}
                        <span class="text-base font-semibold tracking-tight">{{ brandPreview.label }}</span>
                    {% endif %}
                </span>
                <span class="text-xs opacity-60">powered by: EventPhotos by JWdR</span>
            {% else %}
                <span class="text-base font-semibold tracking-tight">EventPhotos by JWdR</span>
            {% endif %}
        </div>

        <h3 class="text-xl font-bold text-base-content">Preview</h3>
        <button type="button" class="btn btn-primary">Meld je aan</button>
    </div>
</div>
```

Notes:
- Logo scaled to `h-6` (real header uses `h-8`) for the compact card; label uses
  `text-base` vs the header's `text-lg`. Structure/classes otherwise mirror the real
  header so it reads as the same element.
- No `<a>` — static per the locked decision.
- The label span consumes `--color-base-content` (font color) and the powered-by
  line uses `opacity-60` on it, exactly as the real header does. The logo `<img>`
  is unaffected by color vars (correct — it's a bitmap). Glow is set on the card by
  the controller and flows behind the header.

### 6. Form templates

Pass the param through the two existing includes:

- `templates/admin/event/form.html.twig:45`
- `templates/admin/collection/form.html.twig:31`

```twig
{% include '_partials/_style_fields.html.twig'
    with {styleForm: form.style, inherited: styleInherited, brandPreview: brandPreview} only %}
```

### 7. `assets/controllers/style_preview_controller.js`

**No change.** The branded header lives inside the `card` target and inherits the
CSS custom properties and glow the controller already sets. The brand is static
relative to the color inputs, so no new live-update wiring is needed.

## Data flow

```
Controller action
  └─ owner (event/collection owner, or getUser() for new)
       └─ BrandPreviewResolver::forOwner(owner)
            ├─ BrandResolver::resolveForOwner(owner) → ResolvedBrand | null
            └─ logoUrl: account_brand_logo | admin_user_brand_logo(owner.id) | null
  └─ render(form.html.twig, { ..., brandPreview })
       └─ include _style_fields.html.twig with { ..., brandPreview }
            └─ static mini-header inside the [data-style-preview-target=card]
                 └─ style_preview_controller restyles the card (unchanged)
```

## Error / edge handling

- **No brand configured** → `forOwner` returns `null` → template renders the default
  `EventPhotos by JWdR` header (matches the public `_base.html.twig` fallback).
- **Brand label but no logo** → `logoUrl` is `null`; label-only header renders.
- **Logo file missing on disk** → the serving route (`account_brand_logo` /
  `admin_user_brand_logo`) already 404s; the `<img>` simply fails to load. No new
  handling needed — this mirrors the existing account/admin logo-preview behavior.
- **Admin editing another organizer's event/collection** → `else` branch generates
  `admin_user_brand_logo(owner.id)`; the admin has `UserVoter::VIEW` on the owner, so
  the request authorizes.

## Testing

**Unit — `BrandPreviewResolver`** (`tests/Unit/Service/Brand/`):
- owner has logo, `owner === currentUser` → `logoUrl` is the `account_brand_logo` path.
- owner has logo, `owner !== currentUser` (admin) → `logoUrl` is the
  `admin_user_brand_logo` path with the owner's id.
- owner has a label but no logo → `label` set, `logoUrl === null`.
- owner has no brand → `forOwner` returns `null`.

Router/Security are mockable; assert the generated path strings and null-branching.

**Functional** (`tests/Functional/`):
- Organizer **with** a brand: GET `/admin/events/new`, `/admin/events/{id}/edit`,
  and the collection new/edit forms → the preview card contains the brand label and,
  when a logo exists, an `<img>` whose `src` points at the current user's logo route.
- Organizer **without** a brand → the preview card shows the default
  `EventPhotos by JWdR` header and no brand `<img>`.
- (If a fixture supports it) admin editing another organizer's event → preview
  `<img>` src is the `admin_user_brand_logo` path for that owner.

## Out of scope / non-goals

- No refactor of the real public header (`templates/public/_base.html.twig`) into a
  shared partial. The preview mirrors its markup but stays a separate, compact,
  static block — lower risk than parameterizing the live header, and the two blocks
  are small enough not to trip the `phpcpd` duplication gate.
- No new logo-serving route.
- No live-update of the brand itself (it is static relative to color inputs).

## Quality gates

Must stay green: `phpstan` level 10, `phpcs` PSR-12, `phpmnd` (no magic numbers in
`src/` — none introduced), `phpcpd`, `rector`, `doctrine:schema:validate` (no schema
change here). PHP attributes only.
