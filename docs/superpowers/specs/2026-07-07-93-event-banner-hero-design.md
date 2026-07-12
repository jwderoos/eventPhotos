# Event banner / hero image — design (#93)

Part of #54 (event styling, phase 2 of 4). Builds on the phase-1 styling foundation
(`StyleResolver` / `ResolvedStyle` / `StyleSettings` and the styled `public/_base.html.twig`
wrapper), but the banner is an image, not a resolved color token — so it does **not** flow
through the three-tier resolve chain.

## Goal

Let an organizer upload a wide hero image for an event's public landing page, shown
full-bleed below the branded header.

## Decisions (locked during brainstorming)

- **Event-tier only.** The banner is set per-event, like the event logo. No cascade to
  `EventCollection` or `OrganizerProfile`. A hero image is inherently event-specific, and
  every extra tier would mean another upload widget, storage mapping, and serve route for a
  feature nobody asked to cascade. This is the intentional departure from the ticket's "same
  three-tier chain" phrasing: the chain is for color *tokens*, not files.
- **Synchronous processing on upload.** The derivative is generated inside the request when
  the organizer saves the event-edit form. No Messenger job, no `Pending` state, no worker
  round-trip — this is a low-volume, one-off admin upload, and immediate feedback (including
  validation errors) is the right UX.
- **Derivative only — no original kept.** We transform on upload and store just the
  normalized hero. No re-crop / re-process feature is planned, so keeping the original would
  double storage for nothing.
- **Full-bleed hero below the header.** The header (brand logo/label + "powered by") stays on
  top and legible; the hero spans edge-to-edge beneath it; contained content follows. This
  keeps the brand readable and does not fight the styled background/glow wrapper.

## Architecture

### Data model & storage

- Two new columns on `Event`:
  - `bannerFilename: ?string`
  - `bannerUpdatedAt: ?DateTimeImmutable`
- **No Vich mapping.** Vich manages the *uploaded original*; we store a processed derivative
  and discard the original, so Vich does not fit. We handle the upload manually, mirroring how
  `Admin\PhotoController::upload` handles files directly rather than through Vich.
- New Flysystem disk `event_banners_storage` → `var/uploads/event-banners/`, added to
  `config/packages/flysystem.yaml`. Local adapter, same shape as the existing logo disks
  (served through a controller, so no `directory_visibility: public` needed).
- Stored filename: `event-<id>.jpg` (one file per event; re-upload overwrites in place).
- Migration generated via `bin/console doctrine:migrations:diff` — **never hand-written**
  (per CLAUDE.md; hand-authored index/constraint names drift from Doctrine's hashes). Edit only
  `getDescription()` if needed.

### Image processing — shared GD resizer

The GD scale/encode logic already lives as private methods (`scaleTo`, `encode`) in
`Service\Photo\DerivativeGenerator`. To avoid `phpcpd` tripping (50-line / 100-token gate) and
to keep a single resize implementation, extract those into a small reusable helper:

- **`Service\Image\GdImageResizer`** — pure functions: decode a source path/bytes to a
  `GdImage`, scale to a bounded long edge preserving aspect ratio, encode to JPEG at a given
  quality. No storage, no Flysystem — just image math.
- `DerivativeGenerator` is refactored to delegate to `GdImageResizer` for its thumb/preview
  scaling. This is a low-risk, pure-function extraction on the photo path; existing
  `DerivativeGenerator` behavior and tests are unchanged.

Banner normalization: bound the **long edge to 1600px** preserving aspect (no forced crop —
the 3:1 / 1200×400 guidance is a recommendation, not a constraint), re-encode **JPEG quality
85**. GD re-encode drops EXIF for free.

### Upload flow

- `Form\EventType` gains:
  - an **unmapped** `bannerFile` (`FileType`) with constraints: `mimeTypes` JPEG + PNG,
    `maxSize: 5M`, label noting the 1200×400 / 3:1 recommendation. (Unmapped because the field
    is not a Vich/entity property — the controller reads it off the form.)
  - a `removeBanner` (`CheckboxType`, unmapped) to clear an existing banner.
- **`Service\Event\BannerUploader`** — the domain service the controller delegates to:
  - `upload(Event $event, UploadedFile $file): void` — validate is-an-image, run
    `GdImageResizer` (bound to 1600px, JPEG q85), write to `event_banners_storage` at
    `event-<id>.jpg`, set `bannerFilename` + `bannerUpdatedAt`.
  - `remove(Event $event): void` — delete the file from storage (if present) and null both
    fields.
  - A corrupt / undecodable image throws, surfaced by the controller as a form error; nothing
    is persisted.
- `Admin\EventController` (edit action) after a valid form submit: on `removeBanner` →
  `BannerUploader::remove`; else if a `bannerFile` was uploaded → `BannerUploader::upload`.
  Controller keeps CSRF/auth/routing; the service owns file I/O and processing (thin-controller
  direction, consistent with #91's intent).

### Serving

- New public route **`public_event_banner`** → `GET /e/{slug}/banner.jpg`
  (in `Public\EventController`, alongside `public_event_brand_logo`).
- Streams the derivative from `event_banners_storage`, mirroring the brand-logo / photo-serve
  pattern:
  - `Cache-Control: public, max-age=31536000, immutable`
  - SHA-1 **ETag** derived from `slug|bannerUpdatedAt` (cache-busts on re-upload).
- **404** when the event has no banner.

### Public rendering

- `templates/public/_base.html.twig` gains an optional hero block **between `<header>` and
  `<main>`**, rendered only when the event has a banner:
  - Full-bleed: breaks out of the `max-w-5xl` container to span edge-to-edge.
  - `<img src="{{ path('public_event_banner', {slug: event.slug}) }}" alt="…">`, 3:1 aspect
    band, `object-cover`.
- The hero is opaque foreground content, so it does not interact with the resolved
  background/glow (which lives on the full-page wrapper). Header/brand remain above it.

## Testing

- **Unit**
  - `GdImageResizer`: bounds the long edge, preserves aspect ratio, emits a valid JPEG;
    small-image upscale behavior matches `DerivativeGenerator`'s existing semantics.
  - `BannerUploader`: `upload` writes a derivative and sets `bannerFilename` +
    `bannerUpdatedAt`; `remove` deletes the file and nulls the fields; a non-image / corrupt
    input throws.
- **Functional**
  - Upload via the event-edit form → `bannerFilename` set; `public_event_banner` returns 200
    with the immutable `Cache-Control` + ETag.
  - No banner set → `public_event_banner` returns 404.
  - `removeBanner` → fields nulled; serve route returns 404.
- **Regression**: existing photo-pipeline tests (`DerivativeGenerator`) still pass after the
  `GdImageResizer` extraction — no behavior change.
- All GrumPHP gates green: phpstan L10, phpcs (PSR-12), phpmnd (no magic numbers in `src/`),
  phpcpd, rector, `doctrine:schema:validate`.

## Out of scope (follow-ups if ever wanted)

- Cascade of the banner to `EventCollection` / `OrganizerProfile` tiers.
- Keeping the uploaded original / a re-crop or reposition UI.
- Showing the hero in the #97 admin styling **preview** (preview stays colors/brand only).
- Banner on any surface other than the public event landing (e.g. collection landing).
