# Photo ingest — design

**Goal:** Let an event organizer bulk-upload JPEG photos through the admin, have them processed asynchronously, and serve a public time-window gallery at `/e/{slug}/photos?t=<ATOM>&w=<minutes>` so attendees see thumbnails of photos taken near a given moment. Originals stay private; derivatives (thumbnail + preview) are public.

**Scope:** Organizer ingest path, async worker, public read-only gallery, admin moderation (delete + retry). Out of scope: attendee uploads, originals download, watermarking, rate limiting, public-side timezone display, CI for the worker, deploy/supervisor setup.

---

## Decisions

| Question | Answer |
|---|---|
| Who uploads | Event organizer only, through the admin. Attendees are read-only. |
| Attendee UX | Thumbnail gallery. Downloads are resized previews, never originals. |
| Watermark | None in v1. Pipeline designed so derivatives can be re-generated with a watermark later. |
| Storage | Local disk via `league/flysystem-bundle`. Three named storages (originals, thumbs, previews). S3 swap is a future adapter change, no schema impact. |
| Upload UX | Uppy uploader (vendored via AssetMapper), per-file XHR with progress, concurrency 3. |
| Async processing | Symfony Messenger, Doctrine transport (no Redis). One queue `async`, one failed transport `failed`. |
| Allowed formats | JPEG only. 25 MB per-file cap. |
| Timestamp | Strict EXIF `DateTimeOriginal`. `OffsetTimeOriginal` preferred when present; otherwise event timezone. No EXIF → photo rejected with status `failed`. |
| Timezone | New `timezone` field on `Event` (IANA string). All `Photo.takenAt` stored as UTC. |
| Dedup | SHA-256 of original bytes. Unique on `(event_id, content_hash)`. Duplicates are silent success. |
| Photo IDs | Sequential integers. Derivative URLs are guessable; acceptable for v1 since only derivatives are web-served. |
| Window cap | Hard `LIMIT 200` on gallery query regardless of `w`. |

---

## Data model

### New entity — `Photo`

```
id                int, PK, sequential
event_id          int, FK to events, NOT NULL, ON DELETE CASCADE
content_hash      char(64), NOT NULL          -- sha256 hex
original_filename varchar(255), NOT NULL      -- display only
byte_size         int, NOT NULL
width             int, NULL                   -- populated by worker
height            int, NULL                   -- populated by worker
taken_at          datetime_immutable, NULL    -- UTC, populated by worker
status            varchar(16), NOT NULL       -- pending|ready|failed
processing_error  text, NULL                  -- human-readable, populated when status=failed
created_at        datetime_immutable, NOT NULL
updated_at        datetime_immutable, NOT NULL

UNIQUE (event_id, content_hash)               -- dedup key
INDEX  (event_id, status, taken_at)           -- public gallery query
```

Status is a typed string enum (`PhotoStatus` PHP enum, `Doctrine\DBAL\Types\StringType` via `enumType:` on the column attribute, or a hand-rolled `Type` — pick the simplest at implementation time).

Status transitions are mediated by methods on `Photo`:
- `markReady(DateTimeImmutable $takenAt, int $width, int $height): void`
- `markFailed(string $reason): void`
- `resetForRetry(): void` — `failed` → `pending`, clears `processing_error`

No public `setStatus()`. Invalid transitions throw `DomainException`.

### Modified entity — `Event`

Add `timezone` (string, `length: 64`, NOT NULL, IANA name, validated with `Symfony\Component\Validator\Constraints\Timezone`). Form gets a `ChoiceType` populated from `DateTimeZone::listIdentifiers(DateTimeZone::ALL)` or a curated short list — implementer's call, but admins must pick something. Migration backfills existing events to `Europe/Amsterdam`, then drops the column default so new inserts require it explicitly.

### Migration

One migration:
1. `CREATE TABLE photos (...)`
2. `ALTER TABLE events ADD COLUMN timezone varchar(64) NOT NULL DEFAULT 'Europe/Amsterdam'`
3. `ALTER TABLE events ALTER COLUMN timezone DROP DEFAULT`

---

## Storage layout

`league/flysystem-bundle` config in `config/packages/flysystem.yaml`:

```yaml
flysystem:
  storages:
    photo.originals:
      adapter: 'local'
      options:
        directory: '%kernel.project_dir%/var/storage/photos/originals'
    photo.thumbs:
      adapter: 'local'
      options:
        directory: '%kernel.project_dir%/var/storage/photos/derivatives/thumbs'
    photo.previews:
      adapter: 'local'
      options:
        directory: '%kernel.project_dir%/var/storage/photos/derivatives/previews'
```

Path within each storage: `event-{eventId}/{photoId}.jpg`. Deterministic from the row, no name collisions, easy bulk cleanup.

`.gitignore` adds `/var/storage/`. The three subtrees are created on demand by Flysystem.

---

## Ingest pipeline

### Upload endpoint

`POST /admin/events/{id}/photos`

- CSRF token in header (Uppy `XHRUpload` plugin can attach it)
- Voter check: `EDIT` on the event
- Body: `multipart/form-data` with one `file` field (Uppy posts one file per request)
- Returns JSON: `{status: "pending"|"duplicate", photoId: <int>}` (`202` for pending, `200` for duplicate)
- Error responses: `415` non-JPEG, `413` over 25 MB, `400` malformed, `403` voter deny

Controller steps (synchronous, no image decoding):
1. Validate mime (`image/jpeg`) and size (`≤ 25 MB`).
2. Compute sha256 of the uploaded file (`hash_file('sha256', $uploaded->getRealPath())`).
3. Check `(event_id, content_hash)` uniqueness. If hit → return `duplicate` with existing photo id, delete the uploaded tmp.
4. Insert `Photo` row: `status=pending`, `original_filename`, `byte_size`, `content_hash`, `created_at`, `updated_at`. Flush to get the id.
5. Move the uploaded tmp into `photo.originals` storage at `event-{id}/{photoId}.jpg` (stream the bytes via Flysystem so this works for non-local adapters later).
6. Dispatch `ProcessPhoto($photoId)` to Messenger.
7. Return `pending`.

If step 5 fails, delete the row and bubble a `500`. If step 6 fails, the row is left `pending`; the orphan-pending sweep (manual console command for v1) can re-dispatch.

### Messenger

`config/packages/messenger.yaml`:

```yaml
framework:
  messenger:
    transports:
      async: '%env(MESSENGER_TRANSPORT_DSN)%'
      failed: 'doctrine://default?queue_name=failed'
    routing:
      'App\Message\ProcessPhoto': async
    failure_transport: failed
```

`MESSENGER_TRANSPORT_DSN=doctrine://default?queue_name=async` in `.env`.

Worker is started in dev with `bin/console messenger:consume async failed -vv`. README documents this; deploy is out of scope.

### `ProcessPhotoHandler`

1. Load `Photo` by id. If `null` (deleted), return.
2. If `status !== pending`, return (idempotent; retry endpoint is responsible for flipping `failed` → `pending` before re-dispatching).
3. Open the original from `photo.originals` storage.
4. Read EXIF with `exif_read_data()`.
   - Require `DateTimeOriginal`. If missing → `throw PhotoRejected('Missing EXIF DateTimeOriginal')`.
   - If `OffsetTimeOriginal` is present and parseable, build a `DateTimeImmutable` with that offset.
   - Otherwise interpret `DateTimeOriginal` in `event.timezone`.
   - Convert to UTC.
5. Decode JPEG with GD (`imagecreatefromjpeg`). Capture width/height.
6. Generate thumbnail: long edge 400px, JPEG quality 80, strip EXIF. Write to `photo.thumbs` at `event-{id}/{photoId}.jpg`.
7. Generate preview: long edge 1600px, JPEG quality 85, strip EXIF. Write to `photo.previews` at the same path.
8. `markReady($takenAt, $width, $height)`, flush.

`PhotoRejected` is caught by the handler itself and routed to `markFailed(...)` — it does **not** propagate to Messenger (no retry: it's a permanent data problem). Any other exception propagates → Messenger retries (3× exponential backoff: 1s, 5s, 25s) → eventually `failed` transport. The photo row stays `pending` while retries happen, flips to `failed` only when the message lands in `failed` (no Messenger middleware exists for this in the framework, so on retry exhaustion we currently can't auto-mark; document this gap — admin sees stuck-pending rows older than 5 minutes via banner, can manually retry).

### Retry endpoint

`POST /admin/events/{id}/photos/{photoId}/retry` (CSRF, voter):
- `failed` photos: `resetForRetry()` + dispatch `ProcessPhoto`. Works for both EXIF-rejected and exhausted-retry rows.
- `pending` photos older than 5 minutes: dispatch again (in case worker missed it).
- `ready` photos: no-op.

### Delete endpoint

`POST /admin/events/{id}/photos/{photoId}/delete` (CSRF, voter):
- Delete files from all three storages (ignore missing files — `photo.originals` may be the only one present if processing never finished).
- Delete the row.
- Any in-flight `ProcessPhoto` message becomes a no-op via step 1 of the handler.

---

## Admin UX

### Photos panel on `/admin/events/{id}/edit`

New Twig partial below the existing form (or above — implementer's call based on layout). Contains:

**Uploader** — Uppy dashboard mode, vendored into `assets/vendor/uppy/` via AssetMapper. Configured:
- `XHRUpload` plugin pointing at the upload endpoint
- `restrictions: { allowedFileTypes: ['.jpg', '.jpeg', 'image/jpeg'], maxFileSize: 25 * 1024 * 1024 }`
- `limit: 3` (concurrency)
- CSRF token injected via meta tag in the layout
- On upload-complete: trigger reload of the photo grid Turbo Frame

**Photo grid** — `<turbo-frame id="photos-grid" src=".../photos-grid">` containing:
- Tile per photo: thumbnail (or status placeholder for `pending`/`failed`), `status` badge (Tailwind/daisyUI), `takenAt` (rendered in event TZ — `|date('H:i', event.timezone)`), `originalFilename`, delete button, retry button (when failed)
- Stimulus controller `photos-poller` watching the frame: while any tile has `data-status="pending"`, refresh the frame every 5s; stop polling otherwise
- Banner above the grid if any tile is `pending` with `created_at > 5 min` and no recent ready photos: "Photos look stuck. Is the worker running?"

`GET /admin/events/{id}/photos-grid` — returns just the frame content. Same voter check.

---

## Public gallery

### Endpoint

Existing route `/e/{slug}/photos` (already wired with `?t=<ATOM>&w=<minutes>` parsing). Controller adds:

```
$start = $timestamp->modify("-{$window} minutes");
$end   = $timestamp->modify("+{$window} minutes");
$photos = $photoRepository->findReadyInWindow($event, $start, $end);
```

`PhotoRepository::findReadyInWindow(Event, DateTimeImmutable, DateTimeImmutable, int $limit = 200): array`

```sql
SELECT * FROM photos
WHERE event_id = :event_id
  AND status = 'ready'
  AND taken_at BETWEEN :start AND :end
ORDER BY taken_at ASC
LIMIT 200
```

Endpoints are inclusive. Hard cap of 200 inside the repository method — gallery template renders a footer if `count() === 200` saying "Showing the first 200 photos in this window. Narrow your time range to see others."

### Gallery template

`templates/public/event/photos.html.twig`: replace the stub block with a Tailwind/daisyUI grid:
- 2 cols mobile, 3 cols sm, 4 cols md+
- Each tile is `<a href="/p/{id}/preview.jpg" target="_blank"><img src="/p/{id}/thumb.jpg" loading="lazy" width="400" alt="..."></a>`
- Empty-state copy if zero photos in window
- Existing "back to event" link remains

No JS lightbox in v1 — preview opens in a new tab.

### Image-serve endpoints

`GET /p/{photoId}/thumb.jpg` and `GET /p/{photoId}/preview.jpg`:
- 404 if photo not found or `status !== ready`
- Stream from the appropriate Flysystem storage
- Headers: `Content-Type: image/jpeg`, `Cache-Control: public, max-age=31536000, immutable`, `ETag` = sha1 of `photoId|updatedAt`
- 304 on `If-None-Match` match

No referer/slug check. Derivatives are intentionally shareable.

---

## Error handling matrix

| Failure | Where caught | Outcome |
|---|---|---|
| Non-JPEG upload | Upload controller mime check | `415`, Uppy per-file error |
| File > 25 MB | Upload controller size check | `413`, Uppy per-file error |
| Hash duplicate | Upload controller, before insert | `200 {status: duplicate}`, no row, no file |
| Missing EXIF `DateTimeOriginal` | `ProcessPhotoHandler`, `PhotoRejected` | `markFailed("Missing EXIF timestamp")`, no Messenger retry |
| Invalid TZ on Event | Admin form, `Constraints\Timezone` | Form validation error, no save |
| GD decode error, disk full, other I/O | Worker, uncaught | Messenger retries 3× exp backoff, lands in `failed` transport. Row stays `pending`. Admin banner surfaces stuck rows. Manual retry resolves. |
| Photo deleted mid-process | Handler step 1 returns | Silent no-op |

## Edge cases

- **DST ambiguity.** EXIF time in DST fall-back overlap (e.g. `2026-10-25 02:30` in `Europe/Amsterdam`) is ambiguous; PHP's default is to pick the first occurrence. Accept this, document it.
- **Server vs event TZ on the landing page.** The existing `/e/{slug}` displays `now()` in server TZ. This spec does not change that; `Photo.takenAt` storage is UTC end-to-end, so the query is correct regardless. A follow-up ticket can localize landing display.
- **Attendee clock skew.** ±W window absorbs phone clock drift.
- **No rate limiting.** Admin upload is voter-gated; public gallery doesn't expose more than the slug already does.
- **Sequential photo IDs.** Derivative URLs `/p/{id}/thumb.jpg` are enumerable. Mitigation: derivatives are downscaled and (future) watermarked; originals are never web-served. Future swap to UUID is schema-local.
- **Pending rows after retry exhaustion.** No automatic flip to `failed`. Documented gap; admin banner + manual retry covers it.

---

## Testing

### Fixtures

`tests/fixtures/photos/`:
- `with-datetime-original.jpg` — small JPEG with `DateTimeOriginal` only (no offset)
- `with-offset-time.jpg` — `DateTimeOriginal` + `OffsetTimeOriginal`
- `no-exif.jpg` — stripped EXIF; used to assert rejection
- `bigger.jpg` — ~2 MB, used for size/streaming tests

### Unit

- `PhotoTest` — status transition methods enforce valid transitions; `resetForRetry` only from `failed`; `markReady` only from `pending`
- `ExifReaderTest` — happy path with offset, fall-back to event TZ, missing tag throws, malformed EXIF throws
- `PhotoVoterTest` — same ownership rules as `EventVoter` (delegate or duplicate)

### Integration (DAMA transactional)

- `ProcessPhotoHandlerTest` — drives the handler end-to-end against a temp Flysystem storage (`sys_get_temp_dir()`); asserts derivative files exist, row state correct, EXIF stripped from derivatives, idempotent on second run
- `PhotoRepositoryTest::findReadyInWindow` — boundary inclusivity, status filter (pending/failed excluded), ordering, hard cap

### Functional

- Upload endpoint: happy, duplicate, oversized, wrong mime, voter deny, CSRF missing
- Retry endpoint: failed → pending, pending → re-dispatched, ready → no-op
- Delete endpoint: removes row + files, CSRF, voter deny
- Public gallery: ready returned, pending hidden, empty window, hard cap surfaced in template
- Image-serve endpoints: 404 on non-ready, correct headers, ETag/304 round-trip

### Manual

- Drag-drop 50 photos through Uppy; observe per-file progress and Turbo Frame refresh
- Kill the worker mid-batch; observe banner; restart; observe pending rows clear

---

## Out of scope (follow-ups)

- Watermark composition into preview derivative (event logo overlay)
- Originals download for organizer
- Public-side TZ display on `/e/{slug}` landing
- Attendee uploads
- Rate limiting on upload and public endpoints
- CI pipeline for the worker
- Deploy/supervisor setup
- UUID swap for photo IDs (if enumeration becomes a concern)
- Bulk delete in admin
- Automatic `failed` flip on Messenger retry exhaustion (needs a custom middleware)
