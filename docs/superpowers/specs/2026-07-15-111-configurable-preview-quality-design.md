# Organizer-configurable display-image quality and size (#111)

## Goal

Give organizers **limited** control over the **preview (display)** derivative's long-edge
size and JPEG quality, per event. Today these are hardcoded constants in
`App\Service\Photo\DerivativeGenerator`. Thumbnails stay fixed. Defaults reproduce
today's values (1600 px / q85) so existing events are unchanged.

## Locked decisions

- Preview long edge: discrete allowlist `{1280, 1600, 2048, 2560}`, default **1600**.
- Preview JPEG quality: discrete allowlist `{70, 80, 85, 90}`, default **85**.
- Thumbnail: **fixed** at 400 px / q80 — not organizer-facing.
- No per-plan / admin cap on maximums — there is no tier/plan concept in the codebase (YAGNI).
- Changing settings does **not** regenerate already-ingested previews. Existing previews keep
  their old dimensions until re-ingested. This is the natural pairing with re-ingest (#112)
  and is out of scope here.

## Design

### 1. Storage — new `PreviewSettings` embeddable

Mirror the existing `StyleSettings` embeddable pattern. New `#[ORM\Embeddable]`
`App\Entity\PreviewSettings`, embedded on `Event` with column prefix `preview_`.

```php
#[ORM\Embeddable]
class PreviewSettings
{
    public const array ALLOWED_LONG_EDGES = [1280, 1600, 2048, 2560];
    public const array ALLOWED_QUALITIES  = [70, 80, 85, 90];
    public const int   DEFAULT_LONG_EDGE  = 1600;
    public const int   DEFAULT_QUALITY    = 85;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => self::DEFAULT_LONG_EDGE])]
    #[Assert\Choice(choices: self::ALLOWED_LONG_EDGES, message: 'Choose a supported preview size.')]
    private int $longEdge = self::DEFAULT_LONG_EDGE;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => self::DEFAULT_QUALITY])]
    #[Assert\Choice(choices: self::ALLOWED_QUALITIES, message: 'Choose a supported preview quality.')]
    private int $quality = self::DEFAULT_QUALITY;

    // getLongEdge/setLongEdge, getQuality/setQuality
}
```

**Why non-nullable columns with DB defaults** (not the nullable/resolve-to-default style
`StyleSettings` uses): `StyleSettings` is nullable because it supports *inheritance* (fall
back to collection style). Preview settings have concrete, non-inherited defaults, so
`options: ['default' => …]` backfills every existing row to today's exact behavior —
satisfying "existing events unchanged" with zero null-handling in generation code. The
embeddable **is** the resolved settings value object the issue asked for.

`Event`:

```php
#[ORM\Embedded(class: PreviewSettings::class, columnPrefix: 'preview_')]
private PreviewSettings $preview;
```

Initialized `new PreviewSettings()` in the constructor (alongside `$this->style = new StyleSettings()`),
exposed via `getPreviewSettings(): PreviewSettings`.

### 2. Generation

`App\Service\Photo\DerivativeGenerator`:

- Remove `PREVIEW_LONG_EDGE` / `PREVIEW_QUALITY` constants (their defaults now live on
  `PreviewSettings`).
- Keep `THUMB_LONG_EDGE` / `THUMB_QUALITY` constants (thumbnail stays fixed).
- Signature: `generate(string $path, PreviewSettings $preview): array` — read
  `$preview->getLongEdge()` and `$preview->getQuality()` for the preview encode.

`App\MessageHandler\ProcessPhotoHandler` (the sole caller): pass
`$event->getPreviewSettings()` into `generate()`.

### 3. Form

New `App\Form\PreviewSettingsType` (`data_class = PreviewSettings`), two `ChoiceType`
fields whose choices come from the allowlist constants:

- `longEdge` — labelled e.g. "Display image size (px)".
- `quality` — labelled e.g. "Display image quality".

Embedded in `EventType`: `->add('preview', PreviewSettingsType::class, ['label' => false])`,
grouped under a "Display image quality" heading in the event form template. Server-side
`Assert\Choice` is the real gate; the `<select>` is UX only.

### 4. Migration

Generate via `bin/console doctrine:migrations:diff` (never hand-written, per CLAUDE.md).
Adds `preview_long_edge` and `preview_quality` INTEGER columns with `DEFAULT 1600` / `DEFAULT 85`.
Existing rows inherit the defaults on migrate. Confirm `doctrine:schema:validate` stays green.

### 5. Tests

- **Unit** `PreviewSettingsTest`: a fresh instance reports 1600 / 85.
- **Unit** `DerivativeGeneratorTest`: pass a `PreviewSettings` into `generate()`. Keep a
  default-case assertion (preview long edge 1600) and add a non-default case (e.g. 2048) that
  asserts the generated preview honors the configured edge.
- **Functional** (event edit): an out-of-allowlist value is rejected (validation error, not
  persisted); a valid non-default value persists; a brand-new event reads 1600 / 85.

## Acceptance criteria (from the issue)

- [ ] `Event` carries bounded preview size + quality settings, defaulting to 1600 / q85.
- [ ] Values are clamped/validated to the allowed range; out-of-range input is rejected.
- [ ] `DerivativeGenerator` uses the event's settings when generating the preview.
- [ ] Newly ingested photos honour the event's configured size/quality.
- [ ] Existing events with no explicit setting behave exactly as before.
