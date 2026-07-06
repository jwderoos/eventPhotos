# Organizer brand in public header — design (#96)

## Goal

Let an organizer attach their own **brand** — a text label and/or a logo, optionally
linked to their brand homepage — shown in the public event page header. Rework the
platform label from the generic "Event Photos" to **"EventPhotos by JWdR"**. When an
organizer has set a brand, the platform label is demoted to a small "powered by" subline.

Brand config is **per-organizer** (applies to all of that organizer's events), not
per-event. "JWdR" is a literal (platform initials).

## Key decisions

1. **Entity home: extend `OrganizerProfile`** (not a new `UserBrandConfig`).
   `OrganizerProfile` already exists as the per-organizer display-config entity — it
   embeds the per-organizer `StyleSettings` tier and is edited on `/account`. Brand is
   per-organizer display config, so it belongs here. The brand fields are added as
   **direct fields on `OrganizerProfile`**, NOT inside the `StyleSettings` embeddable —
   honoring the issue's "separate from per-event StyleSettings (#54)" intent. This avoids
   a new entity/repository/controller/edit-page. (`UserMailConfig` is a separate entity
   because it is a heavy, security-sensitive concern with an encrypted DSN + verification
   lifecycle; brand is three plain fields + a logo, much closer to the style config
   already on `OrganizerProfile`.)

2. **Logo serving: event-scoped public route** `GET /e/{slug}/brand-logo.png`. The brand
   is per-organizer but only ever shown in the context of an event page. Serving it via
   the event slug keeps it in the public no-session zone, leaks no organizer ID or storage
   path, and mirrors the existing logo-serve controller.

3. **"powered by" subline is plain text** (non-clickable). Only the organizer's brand
   (logo/label) is the header link. In the no-brand default, the platform label keeps its
   existing behavior of linking to the public home.

## Data model — `OrganizerProfile`

Add to `src/Entity/OrganizerProfile.php` (annotate the class `#[Vich\Uploadable]`):

- `brandLabel: ?string` — `#[ORM\Column(length: 120, nullable: true)]`
- `brandLogoFilename: ?string` — `#[ORM\Column(length: 255, nullable: true)]`
- `brandLogoUpdatedAt: ?DateTimeImmutable` — `#[ORM\Column(type: DATETIME_IMMUTABLE, nullable: true)]`
  (Vich bumps this on upload; also feeds the public ETag.)
- `brandUrl: ?string` — `#[ORM\Column(length: 512, nullable: true)]`
- Non-mapped `?File $brandLogoFile` with
  `#[Vich\UploadableField(mapping: 'brand_logo', fileNameProperty: 'brandLogoFilename')]`
  and `#[Assert\File(maxSize: '2M', mimeTypes: ['image/png', 'image/jpeg'], mimeTypesMessage: ...)]`.
- `#[Assert\Url(protocols: ['http', 'https'])]` on `brandUrl`.

Domain helper (single source of truth for "brand is set"):

```php
public function hasBrand(): bool
{
    return $this->brandLabel !== null || $this->brandLogoFilename !== null;
}
```

Plus getters/setters for each field and the Vich file (setter on `brandLogoFile` must
touch `brandLogoUpdatedAt` per the Vich convention already used by `Event`).

**Transparency:** PNG is an allowed mime type and the serve route streams the stored
bytes verbatim — no compositing, no background. Transparency is preserved for free.
Logo/background legibility is explicitly the organizer's responsibility (issue non-goal).

## Storage — new Vich mapping + Flysystem disk

Mirror the existing `event_logo` wiring exactly.

`config/packages/flysystem.yaml` — new storage:

```yaml
brand_logos_storage:
    adapter: 'local'
    options:
        directory: '%kernel.project_dir%/var/uploads/brand-logos'
```

`config/packages/vich_uploader.yaml` — new mapping:

```yaml
brand_logo:
    uri_prefix: ''
    upload_destination: brand_logos_storage
    namer: Vich\UploaderBundle\Naming\SmartUniqueNamer
    delete_on_remove: true
    delete_on_update: true
```

## Admin UI — `/account` "Branding defaults" section

Extend `App\Form\OrganizerProfileType` with:

- `brandLabel` — `TextType`, `required: false`
- `brandLogoFile` — `VichImageType` with `allow_delete: true` (gives upload + a "remove"
  checkbox for free), `download_uri: false`, `required: false`
- `brandUrl` — `UrlType`, `required: false`, `default_protocol: null`

The existing `AccountController::changeStyle` POST handler (`account_change_style`)
already binds and persists the whole `OrganizerProfile` via `OrganizerProfileType`, so
upload/remove flows through Vich with **no new controller action**. The form must be
rendered with `enctype` (VichImageType needs multipart) — verify the `form_start` on the
account branding section emits it. Relabel/split the section so the brand fields read as
distinct from the color fields.

## Public serving — event-scoped route

New action in `App\Controller\Public\EventController`:

```
GET /e/{slug}/brand-logo.png   name: public_event_brand_logo
```

- Resolve the event by slug (reuse `resolve()`), get `event.getOwner()`.
- Load that owner's `OrganizerProfile` (via `OrganizerProfileRepository`); read
  `brandLogoFilename`. `null` → 404.
- Stream bytes from the injected `brand_logos_storage` `FilesystemOperator`
  (`#[Autowire(service: 'brand_logos_storage')]`). `FilesystemException` → 404.
- Headers: `Content-Type` from extension (reuse the mime-from-extension helper pattern),
  `Cache-Control: public, max-age=...` and an ETag derived from
  `ownerId|brandLogoUpdatedAt` so a re-upload busts caches. Support `If-None-Match`
  (`isNotModified`) like the photo-serve route.
- Public, anonymous, **touches no session** — no flash, no CSRF, no `getSession()`.

## Resolution → template

`App\Service\Brand\BrandResolver`:

```php
public function resolve(Event $event): ?ResolvedBrand
```

- Load the event owner's `OrganizerProfile`. If absent or `!hasBrand()` → return `null`.
- Otherwise return a `ResolvedBrand` DTO.

`App\Service\Brand\ResolvedBrand` — `final readonly` DTO:

```php
public function __construct(
    public ?string $label,
    public bool $hasLogo,
    public ?string $url,   // null/empty => render brand content without an anchor
) {}
```

The resolver stays free of routing/URL generation; the template builds the logo `src`
from the event slug when `hasLogo` is true.

`EventController::landing` and `EventController::photos` each pass
`'brand' => $this->brandResolver->resolve($event)` alongside the existing
`resolvedStyle`. Only these two event routes receive `brand`; home / invitation /
notification pages have no organizer context and fall through to the platform default.

## `templates/public/_base.html.twig`

**Footer** (always, unchanged structure, new text):

```
© {{ "now"|date("Y") }} EventPhotos by JWdR
```

**Header:**

- `brand` undefined or null (default): a link to `public_home` reading
  **EventPhotos by JWdR** (keeps the current "header label links home" behavior).
- `brand` set:
  - Primary block, all inside one `<a href="{{ brand.url }}">` when `brand.url` is
    non-empty, else the same content with **no anchor** (no dead link):
    - `<img src="{{ path('public_event_brand_logo', {slug: event.slug}) }}" alt="...">`
      rendered only when `brand.hasLogo`
    - `{{ brand.label }}` rendered only when `brand.label` is non-empty
    - logo + label sit side by side
  - Beneath it, a small plain-text subline: `powered by: EventPhotos by JWdR`

The header must guard on `brand is defined and brand` (mirroring the existing
`resolvedStyle is defined and resolvedStyle` guard) so non-event pages render the default.

## Migration

Generate with `bin/console doctrine:migrations:diff` for the four new
`organizer_profiles` columns (`brand_label`, `brand_logo_filename`,
`brand_logo_updated_at`, `brand_url`). Never hand-written (per CLAUDE.md). Edit only the
`getDescription()` text if useful. Then `doctrine:schema:validate` must stay green.

## Testing

- **Unit** — `OrganizerProfileTest`: `hasBrand()` truth table (neither → false, label
  only → true, logo only → true, both → true). `BrandResolverTest`: null when profile
  absent / brand unset; populated `ResolvedBrand` (label, hasLogo, url) when set.
- **Functional** — public event header:
  - no brand → header + footer read "EventPhotos by JWdR" (footer keeps "©").
  - brand with label + logo + url → header renders `<img>` + label inside an anchor to
    `brandUrl`; subline "powered by: EventPhotos by JWdR" present.
  - brand with empty `brandUrl` → brand content rendered with **no anchor**.
  - `/e/{slug}/brand-logo.png` streams bytes for a configured brand; 404 when unset;
    `If-None-Match` returns 304 on ETag match.
  - **Session audit**: a request to `/e/{slug}/brand-logo.png` creates no `sessions`
    row (per the CLAUDE.md public-route session discipline).
- **Admin functional** — organizer sets brand label + uploads logo + sets URL on
  `/account` and the values persist; the VichImageType delete checkbox removes the logo.

## Out of scope (per issue)

- Per-event brand overrides (per-organizer only for now).
- Contrast detection / auto-outlining / background compositing for legibility — explicit
  non-goal; the organizer owns both logo and colors.
- Colors / fonts / banner — covered by #54 / #93 / #94.
