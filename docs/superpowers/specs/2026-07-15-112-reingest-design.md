# Re-ingest event images (#112)

Regenerate photo derivatives (thumb + preview) from retained originals, re-running the
ingest pipeline as if the images were freshly uploaded — recomputing derivatives,
EXIF/`takenAt`, and dimensions from the surviving original.

**Primary use case:** pick up new display size/quality settings (#111) for photos already
ingested under the old settings.

## Preconditions

- **Requires retain-originals (#110) to be on for the event.** `retainOriginals` is only
  toggleable while the event has 0 photos, so the event-level flag is a reliable gate: if
  it is on, *every* photo has a retained original; if off, none do. Re-ingest is therefore
  gated purely on `Event::isRetainOriginals()` — no per-photo original-existence probe is
  needed. If the flag is off, both re-ingest actions are hidden in the UI **and** refused
  in the controller.

## Decisions (locked during brainstorming)

1. **Scope/UI:** bulk (re-ingest all) **and** per-photo.
2. **Target statuses:** **Ready only.** Bulk resets `Ready → Pending`; in-flight `Pending`
   photos are skipped (cannot be reset), `Failed` photos are ignored. Failed-photo retry is
   explicitly **out of scope** for this ticket (there is no retry UI today and none is added
   here).
3. **Window guard:** **skipped** on re-ingest. An already-accepted photo must never be
   re-rejected if the event window was later narrowed.
4. **Old derivatives:** **delete then regenerate**, performed in the **worker/handler**
   (gated on the re-ingest flag), not the controller — a bulk re-ingest of a large event
   would otherwise do hundreds of synchronous Flysystem deletes inside the HTTP request.
   Keeping the controller thin (reset + dispatch + one flush) distributes the storage churn
   across the worker and gives clean failure semantics: if preview generation dies after the
   thumb is written, the photo ends `Failed` with *no* stale mismatched pair.

## Design

### Domain — `Photo` (`src/Entity/Photo.php`)

Add an explicit transition mirroring `resetForRetry`, but guarding on `Ready`:

```php
public function resetForReingest(): void
{
    if ($this->status !== PhotoStatus::Ready) {
        throw new DomainException(sprintf(
            'Photo %d cannot be reset for re-ingest from %s.',
            (int) $this->id,
            $this->status->value,
        ));
    }

    $this->processingError = null;
    $this->status = PhotoStatus::Pending;
}
```

Leaves `takenAt`/`width`/`height`/`derivativeBytes` intact — they are overwritten by
`markReady` when the worker completes, so the row keeps rendering dimensions while queued.

### Message — `ProcessPhoto` (`src/Message/ProcessPhoto.php`)

Add a backward-compatible flag (default `false`, so existing dispatch sites are unchanged):

```php
final readonly class ProcessPhoto
{
    public function __construct(public int $photoId, public bool $reingest = false)
    {
    }
}
```

### Handler — `ProcessPhotoHandler` (`src/MessageHandler/ProcessPhotoHandler.php`)

Unchanged idempotency guard (no-op unless `Pending`). When `$message->reingest`:

- **Skip the time-window guard** (the `PhotoRejected` validation of `takenAt` against the
  event window). `takenAt` is still recomputed from EXIF — same original yields the same
  value, but it is never used to reject.
- **Delete existing derivatives before regenerating**, via a new
  `DerivativeGenerator::delete(string $path)` (the generator already owns
  `photo_thumbs_storage` and `photo_previews_storage`).

`maybeDeleteOriginal()` is unchanged: `Event::isRetainOriginals()` is true (precondition),
so the original survives and re-ingest remains repeatable. `derivativeBytes` is refreshed by
`markReady`, so any event-level storage aggregate derived from per-photo `derivativeBytes`
self-corrects once the worker completes — no special accounting.

Resulting flow:

```
if photo missing or status != Pending: return
path = "event-<eventId>/<photoId>.jpg"
stage original -> tmp file
takenAt = ExifReader(tmp, event timezone)
if not reingest: validate takenAt within event window (throws PhotoRejected -> markFailed)
if reingest: derivatives->delete(path)
[w, h, bytes] = derivatives->generate(path, event->getPreviewSettings())
photo->markReady(takenAt, w, h, bytes)
flush
maybeDeleteOriginal(event, path, photoId)   // no-op when retainOriginals on
```

### Service — `DerivativeGenerator` (`src/Service/Photo/DerivativeGenerator.php`)

Add:

```php
public function delete(string $path): void
{
    // best-effort remove thumb + preview at $path from their storages
}
```

### Controller — `Admin\PhotoController` (`src/Controller/Admin/PhotoController.php`)

Two new POST actions. Both: `denyAccessUnlessGranted(EventVoter::EDIT, $event)`, CSRF via
`isCsrfTokenValid`, `#[Audited(...)]`, and **refused unless `event.isRetainOriginals()`**
(flash error + redirect to the manage page; the UI already hides the controls, so a soft
refusal is sufficient).

- **`reingestAll`** — `POST /admin/events/{id}/photos/reingest`
  (name `admin_photo_reingest_all`, CSRF token `reingest_all_photos_<eventId>`):
  query the event's `Ready` photos, call `resetForReingest()` + dispatch
  `ProcessPhoto($id, reingest: true)` for each, single flush, flash "Re-ingesting N photos".
  `#[Audited(AuditAction::PhotoReingestAll, targetParam: 'id', targetType: 'Event')]`.

- **`reingest`** — `POST /admin/events/{eventId}/photos/{photoId}/reingest`
  (name `admin_photo_reingest`, CSRF token `reingest_photo_<photoId>`):
  load the single photo, assert it belongs to the event and is `Ready`, `resetForReingest()`
  + dispatch `ProcessPhoto($id, reingest: true)`, flush. Response mirrors the existing
  per-photo delete action (Turbo).
  `#[Audited(AuditAction::PhotoReingest, targetParam: 'photoId', targetType: 'Photo')]`.

### Audit — `AuditAction` (`src/Audit/AuditAction.php`)

Add two cases (the existing unused `PhotoRetry` is left untouched):

```php
case PhotoReingest = 'photo.reingest';
case PhotoReingestAll = 'photo.reingest_all';
```

### UI

Both controls are **only rendered when `event.retainOriginals`**:

- **Bulk:** "Re-ingest all photos" button in the bulk-actions block of
  `templates/admin/event/photos_grid.html.twig`, alongside delete-all, with a confirmation
  dialog.
- **Per-photo:** "Re-ingest" button in `templates/admin/event/_photo_row.html.twig`, shown
  only on `Ready` rows.

**Progress/feedback:** reuse the existing pending badge and the grid's stale-pending
detector. No new polling or progress bar (YAGNI) — photos flip to the pending badge and
return to ready as the worker completes.

## Testing

- **Unit** (`tests/Unit/Entity/PhotoTest.php`): `resetForReingest` happy path
  (`Ready → Pending`); illegal-move `DomainException` from `Pending` and from `Failed`.
- **Integration**: `DerivativeGenerator::delete` removes both the thumb and the preview file.
- **Functional** (`tests/Functional/`):
  - Bulk re-ingest on a retain-originals event resets all `Ready` photos to `Pending` and
    dispatches N `ProcessPhoto` messages with `reingest: true` (assert on the test transport).
  - Bulk **and** per-photo re-ingest are refused (redirect + flash, no dispatch) when
    `retainOriginals` is off.
  - Per-photo re-ingest is refused for a non-`Ready` photo and for a photo belonging to a
    different event.
  - Invalid CSRF token is rejected for both actions.
  - Handler with `reingest: true` **skips the window guard** (a photo whose `takenAt` is
    outside the event window still ends `Ready`) and regenerates using the event's *current*
    `PreviewSettings` (assert the new preview dimensions differ from the old ones).
  - Handler idempotency preserved (a `reingest` message for a non-`Pending` photo no-ops).

## Acceptance criteria (from #112)

- [x] Re-ingest is only available when the event retains originals (#110).
- [x] Triggering re-ingest deletes existing thumb + preview derivatives and re-dispatches
      `ProcessPhoto` for the affected photos.
- [x] Photos transition `Ready → Pending` via an explicit domain transition
      (`resetForReingest`), then back to `Ready` with freshly generated derivatives.
- [x] Re-ingested photos honour the event's current display size/quality settings (#111).
- [x] No orphaned/stale derivative files remain after re-ingest (deterministic path,
      deleted then regenerated).

## Out of scope

- Failed-photo retry / retry UI (`resetForRetry` stays unwired).
- Live progress bars / polling beyond the existing pending badge.
- Re-ingest for events with `retainOriginals` off (impossible — no original to regenerate
  from).
