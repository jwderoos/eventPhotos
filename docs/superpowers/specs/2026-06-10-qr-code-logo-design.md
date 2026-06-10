# QR code logo overlay — design

**Goal:** Let an event organizer upload a logo to an event and have it rendered in the center of the event's QR code on the print page and PNG download.

**Builds on:** `2026-06-10-qr-code-generation-design.md` (the base QR feature).

**Scope:** Adds an optional logo upload to `Event`, introduces upload + storage infrastructure for the first time in the project, and extends `QrCodeRenderer` to overlay the logo when present. No user-account-level default logo (deferred).

---

## Decisions

| Question | Answer |
|---|---|
| Where does the logo live? | New optional field on `Event` (`logoFilename`, nullable). |
| User-level default logo fallback? | Out of scope. A `// TODO` hook is left in the controller where the fallback will plug in later. |
| Upload library | `vich/uploader-bundle` for entity-file lifecycle (form integration, delete checkbox, namer, dirty-tracking). |
| Storage abstraction | `league/flysystem-bundle`. Local adapter in dev, env-DSN-driven in prod (S3/GCS). Vich configured to use the Flysystem storage. |
| Accepted formats | PNG and JPEG only. SVG rejected (would require pre-rasterization). |
| Max upload size | 2 MB. |
| File serving | Streamed through a Symfony controller, not exposed via `public/`. Survives a swap to S3/GCS unchanged. |
| Logo placement in QR | Centered, ~20% of QR width, white "punchout" background. |
| Error correction | `High` when a logo is present, `Medium` otherwise (existing behavior preserved). |
| Logo-read failure | Logged at WARNING; QR renders without the logo. Scanning matters more than branding. |
| Access control | Logo-serving route uses the same `EventVoter::VIEW` attribute as the QR routes. |

---

## Architecture

### New dependencies

- `league/flysystem-bundle` (runtime).
- `vich/uploader-bundle` (runtime).
- `league/flysystem-memory` (dev/test) — in-memory adapter for fast, self-cleaning functional tests.

### Configuration

**`config/packages/flysystem.yaml`**

```yaml
flysystem:
    storages:
        event_logos_storage:
            adapter: 'local'
            options:
                directory: '%kernel.project_dir%/var/uploads/event-logos'
```

In prod, the adapter is overridden via env DSN (e.g. `STORAGE_DSN_EVENT_LOGOS=aws-s3-v3://...`). Exact prod wiring lives outside this spec — the bundle supports it natively, the application code does not change.

**`config/packages/vich_uploader.yaml`**

```yaml
vich_uploader:
    db_driver: orm
    storage: flysystem
    mappings:
        event_logo:
            uri_prefix: ~  # we don't expose Vich-generated URLs; serving goes through our controller
            upload_destination: event_logos_storage
            namer: Vich\UploaderBundle\Naming\SmartUniqueNamer
            delete_on_remove: true
            delete_on_update: true
```

**`config/packages/test/flysystem.yaml`**

```yaml
flysystem:
    storages:
        event_logos_storage:
            adapter: 'memory'
```

### Storage layout

Files live outside `public/` so they cannot be fetched without going through the access-controlled route:

```
var/uploads/event-logos/{slug-or-id}-{random}.{ext}
```

The `SmartUniqueNamer` derives a unique filename from the entity and a random suffix, preserving the original extension. Two events uploading a file named `logo.png` get distinct stored filenames.

---

## Data model

### `Event` entity additions

```php
#[Vich\Uploadable]
class Event implements Stringable
{
    // ... existing fields ...

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $logoFilename = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $logoUpdatedAt = null;

    #[Vich\UploadableField(mapping: 'event_logo', fileNameProperty: 'logoFilename')]
    #[Assert\File(
        maxSize: '2M',
        mimeTypes: ['image/png', 'image/jpeg'],
        mimeTypesMessage: 'Please upload a PNG or JPEG image.',
    )]
    private ?File $logoFile = null;
}
```

Getters and setters for all three fields. The `setLogoFile()` setter sets `logoUpdatedAt` to `new DateTimeImmutable()` so Doctrine sees the entity as dirty and Vich persists the upload — this is the standard Vich pattern, the `logoUpdatedAt` field is functionally a write-marker.

### Migration

One Doctrine migration adds both columns to the `events` table, both nullable. Existing rows retain `NULL` for both, which is the correct "no logo" default.

---

## Form & UI

### `EventType` change

One new field:

```php
->add('logoFile', VichFileType::class, [
    'required' => false,
    'label' => 'Logo',
    'allow_delete' => true,
    'download_uri' => false,
    'image_uri' => false,
])
```

- `allow_delete: true` exposes Vich's "remove" checkbox on edit so users can clear a previously uploaded logo.
- `download_uri: false` / `image_uri: false` suppress Vich's default preview rendering — we render our own via the access-controlled serving route.

### Edit template change

`templates/admin/event/edit.html.twig` — render a preview thumbnail above the file input when a logo exists:

```twig
{% if event.logoFilename %}
    <img src="{{ path('admin_event_logo', {id: event.id}) }}"
         alt="" class="h-20 w-20 object-contain border rounded mb-2" />
{% endif %}
{{ form_row(form.logoFile) }}
```

The form's `<form>` tag must have `enctype="multipart/form-data"`. If the existing template uses `{{ form_start(form) }}`, Symfony adds this automatically once a file field is present. If the existing template uses a manual `<form>` element, we set the attribute explicitly.

The "new event" template needs no changes — there is no existing logo to preview, and the `VichFileType` field is added by `EventType`.

### Index page

No changes. The logo is managed inside the event edit form, not via a separate action.

---

## Controllers

### Constructor injection

`EventController` gains two constructor-injected services used by both the new logo route and the updated QR routes:

```php
public function __construct(
    #[Autowire(service: 'event_logos_storage')]
    private readonly FilesystemOperator $eventLogosStorage,
    private readonly LoggerInterface $logger,
) {}
```

`league/flysystem-bundle` registers each named storage as a service; the `#[Autowire]` attribute binds the named one. If the controller already has other constructor dependencies, these are added alongside.

### New route — serve the stored logo

```php
#[Route('/admin/events/{id}/logo', name: 'admin_event_logo', methods: ['GET'], requirements: ['id' => '\d+'])]
public function logo(Event $event): Response
{
    $this->denyAccessUnlessGranted(EventVoter::VIEW, $event);

    if ($event->getLogoFilename() === null) {
        throw $this->createNotFoundException();
    }

    try {
        $contents = $this->eventLogosStorage->read($event->getLogoFilename());
    } catch (FilesystemException) {
        throw $this->createNotFoundException();
    }

    $response = new Response($contents);
    $response->headers->set('Content-Type', $this->mimeFromExtension($event->getLogoFilename()));
    $response->headers->set('Cache-Control', 'private, max-age=300');

    return $response;
}
```

The MIME type is derived from the file extension; since the upload validator restricts to PNG/JPEG, `mimeFromExtension()` is a two-case `match`.

`Cache-Control: private, max-age=300` — short cache so admins see updated logos quickly after replacing them.

### Updated QR routes

Both `qr()` and `qrPng()` actions read the logo bytes (if any) and pass them to the renderer:

```php
$logoBytes = $this->readLogoBytes($event);
// TODO: when user-level default logos exist, fall back to
// $event->getOwner()->getDefaultLogo() bytes here when $event has no logo of its own.

return new Response(
    $renderer->svg($publicUrl, $logoBytes),
    Response::HTTP_OK,
);
```

`readLogoBytes()` is a small private helper on the controller:

```php
private function readLogoBytes(Event $event): ?string
{
    if ($event->getLogoFilename() === null) {
        return null;
    }
    try {
        return $this->eventLogosStorage->read($event->getLogoFilename());
    } catch (FilesystemException $e) {
        $this->logger->warning('Failed to read event logo; rendering QR without it', [
            'event_id' => $event->getId(),
            'filename' => $event->getLogoFilename(),
            'exception' => $e,
        ]);
        return null;
    }
}
```

A missing-or-unreadable logo degrades to a plain QR rather than a 500. Scanning is more important than branding.

---

## `QrCodeRenderer` changes

### Signature

```php
public function svg(string $url, ?string $logoContents = null, ?int $size = null): string;
public function png(string $url, ?string $logoContents = null, ?int $size = null): string;
```

The logo is passed as raw binary bytes, not a path. Keeps the renderer decoupled from storage; trivial to unit-test with fixture bytes; no filesystem touched in tests.

### endroid integration

```php
$size = $size ?? self::DEFAULT_SIZE;
$builder = new Builder(
    writer: $writer,
    data: $url,
    size: $size,
    margin: self::MARGIN,
    errorCorrectionLevel: $logoContents !== null
        ? ErrorCorrectionLevel::High
        : ErrorCorrectionLevel::Medium,
    logoPath: $logoContents !== null ? $tempPath : null,
    logoResizeToWidth: $logoContents !== null ? (int) ($size * 0.20) : null,
    logoPunchoutBackground: true,
);
```

### Temp-file dance

endroid's `logoPath` requires a filesystem path, not bytes. Encapsulated in one private method:

```php
private function withTempLogo(?string $logoContents, callable $fn): string
{
    if ($logoContents === null) {
        return $fn(null);
    }
    $path = tempnam(sys_get_temp_dir(), 'qrlogo_');
    if ($path === false) {
        throw new RuntimeException('Failed to create temp file for QR logo.');
    }
    try {
        file_put_contents($path, $logoContents);
        return $fn($path);
    } finally {
        @unlink($path);
    }
}
```

Both `svg()` and `png()` route through this helper. Cleanup is guaranteed via `finally`.

### Constants

The existing `DEFAULT_SVG_SIZE = 320` and `DEFAULT_PNG_SIZE = 512` constants are preserved. Logo width is computed per-call as `(int)($size * 0.20)`, so the SVG default produces a 64 px logo and the PNG default a 102 px logo.

---

## Testing

### Unit — `tests/Unit/Service/QrCodeRendererTest.php`

Existing cases (SVG/PNG without logo) continue to pass — `logoContents` defaults to `null`, no behavior change.

New cases:

- `svg()` with valid PNG logo bytes returns a string containing `<svg` AND differs from the no-logo output for the same URL.
- `png()` with valid PNG logo bytes returns binary starting with PNG magic bytes AND differs from the no-logo output.
- `svg()` with invalid logo bytes (e.g. `'not an image'`) throws an exception — proves we don't silently render garbage.

Logo fixture: a tiny 4×4 PNG checked into `tests/fixtures/logo.png` (~100 bytes).

### Functional — `tests/Functional/Admin/EventQrTest.php` (extend)

Three new cases on top of the existing three:

- **Event with logo → PNG QR differs from no-logo PNG.** Alice uploads a logo via the edit form, then `GET /admin/events/{id}/qr.png` returns 200 PNG with a different byte length than the same event's no-logo PNG. Byte-length delta is sufficient evidence that the logo path was exercised; exact-byte comparison would be brittle across endroid versions.
- **Logo route honors the voter.** Bob (non-owner) `GET /admin/events/{id}/logo` → 403.
- **Missing storage file degrades gracefully.** `logoFilename` is set on the entity to a value that does not exist in the test Flysystem; `GET /admin/events/{id}/qr` returns 200 with a plain QR (no exception, no 500).

### Functional — `tests/Functional/Admin/EventLogoUploadTest.php` (new)

- **Upload happy path.** Alice POSTs to event edit with a multipart PNG → 302 redirect; entity reloads with `logoFilename` populated; file exists in the in-memory Flysystem.
- **Reject SVG.** Alice uploads `logo.svg` → form re-renders with a validation error; no file written.
- **Reject oversize.** Alice uploads a 3 MB PNG → validation error.
- **Delete via Vich checkbox.** Alice edits an event with an existing logo, checks "remove", submits → `logoFilename` is `NULL` on the entity; file removed from storage.

---

## What's not in scope

- User-account-level default logo (option #2 of the original ask). A `// TODO` hook is left in the QR controller; building it is a future story that also requires a new user profile/settings page.
- SVG logos as input (rejected at the validator).
- Cover photos / event banners / any other uploadable asset. The Vich + Flysystem infrastructure introduced here will support them when those features are added.
- Cloud-storage wiring for prod (the bundle supports it; the production env DSN is outside this spec).
- Cache-busting URLs for the logo route (`logoUpdatedAt` is reserved for this future use).

---

## Files added / changed

**Added:**
- `config/packages/flysystem.yaml`
- `config/packages/vich_uploader.yaml`
- `config/packages/test/flysystem.yaml`
- `migrations/VersionYYYYMMDDHHMMSS.php` (event logo columns)
- `tests/fixtures/logo.png`
- `tests/Functional/Admin/EventLogoUploadTest.php`

**Modified:**
- `composer.json` / `composer.lock` (add `league/flysystem-bundle`, `vich/uploader-bundle`, `league/flysystem-memory` as dev dep)
- `src/Entity/Event.php` (logo fields, `#[Vich\Uploadable]`)
- `src/Form/EventType.php` (one new field)
- `src/Service/QrCodeRenderer.php` (optional `?string $logoContents` arg, temp-file helper, conditional error correction)
- `src/Controller/Admin/EventController.php` (new `logo()` action; `qr()` / `qrPng()` read logo bytes; constructor gets `FilesystemOperator` + `LoggerInterface`)
- `templates/admin/event/edit.html.twig` (logo preview + form field)
- `tests/Unit/Service/QrCodeRendererTest.php` (new cases)
- `tests/Functional/Admin/EventQrTest.php` (new cases)

**Unchanged but used:**
- `src/Security/Voter/EventVoter.php` — `VIEW` attribute continues to gate the new logo route alongside the existing QR routes.
