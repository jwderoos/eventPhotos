# Event Export / Import — design

**Date:** 2026-07-08
**Issue:** #101
**Status:** Approved design → implementation plan pending
**Branch:** `feature/101-event-export-import`

## Context

Organizers need to move an event between accounts and between instances (the
TrueNAS box and the new OVH VPS) as a self-contained, portable archive. Today an
event is stitched across a Doctrine row, embedded style settings, Flysystem image
files, and child rows (photos, subscriptions); there is no way to lift one out and
recreate it elsewhere. This feature adds an **Export** action (download a `.zip`)
and an **Import** action (upload the `.zip` to recreate the event).

**Scope rule: "event down, not up."** Everything directly owned by the `Event`
travels with it. Anything the event merely *belongs to* does not — `EventCollection`
(parent) and the owner `User` / account-level settings (e.g. mail config) are
**excluded**. (A future collection-export is a separate ticket.)

## Decisions (locked during brainstorming)

1. **Photo payload — both derivatives, imported as `Ready`.** Originals are deleted
   post-ingest (`ProcessPhotoHandler::deleteOriginalQuietly`); only the thumb +
   preview persist. The archive bundles both derivative JPEGs plus photo metadata.
   Import recreates each `Photo` directly in `Ready` state from the bundled files —
   **no `ProcessPhoto` dispatch, no GD work, no re-derivation.**
2. **Subscriptions — included.** `EventNotificationSubscription` rows (email +
   opt-in status + timestamps) travel with the event. **Tokens are regenerated on
   import** — the confirmation/unsubscribe tokens are opaque lookup keys, so the
   audience and opt-in state carry over intact while live secrets never enter the
   archive.
3. **Publish state — preserved as exported.** `publishedAt` and
   `notificationsEnabled` copy over verbatim. Import does **not** re-fire the
   live-notification flow (publishing stays a separate explicit action).
4. **Delivery — synchronous download.** Export builds the ZIP to a temp file in the
   request and streams it back (`BinaryFileResponse`, delete-after-send). No
   Messenger job, no artifact storage.
5. **Slug — keep exported slug, refuse on collision.** No auto-rename, no overwrite.

## Defaults (flagged, low-risk)

- **Only `Ready` photos are exported.** `Pending`/`Failed` photos have no servable
  derivatives on disk, so they cannot be faithfully carried. The manifest records a
  `skippedPhotos` count for transparency.
- **Image assets exported:** the Vich-managed **logo** only. (The banner feature
  #93 is not merged to `main`; when it lands, adding it to the archive is a small
  follow-up — one extra `images/banner/` entry and a storage copy.)
- **No schema change / no migration.** Import reuses existing constructors and
  state-transition methods; only two `AuditAction` enum cases are added (the audit
  action column is a string — no DB migration).

## Archive format

`event-export-<slug>.zip`:

```
manifest.json
images/
  logo/<logoFilename>            (if the event has a logo)
  photos/
    <contentHash>.thumb.jpg
    <contentHash>.preview.jpg
```

Files are keyed by `contentHash` (DB-id-independent, so archives are portable
across instances). `manifest.json`:

```json
{
  "format": "eventphotos.event-export",
  "version": 1,
  "exportedAt": "2026-07-08T10:00:00+00:00",
  "sourceInstance": "<DEFAULT_URI>",
  "event": {
    "name": "...", "slug": "...", "description": "..."|null, "timezone": "...",
    "startsAt": "...", "endsAt": "...",
    "publishedAt": "..."|null, "notificationsEnabled": false,
    "style": { "fontColor": "..."|null, "backgroundColor": "..."|null,
               "buttonColor": "..."|null, "glowEnabled": true|false|null },
    "logo": { "filename": "..." }|null
  },
  "photos": [
    { "contentHash": "...", "originalFilename": "...", "byteSize": 123,
      "width": 4000, "height": 3000, "takenAt": "..."|null,
      "derivativeBytes": 210000, "createdAt": "..." }
  ],
  "subscriptions": [
    { "email": "...", "status": "confirmed",
      "confirmedAt": "..."|null, "unsubscribedAt": "..."|null,
      "notifiedAt": "..."|null, "createdAt": "..." }
  ],
  "skippedPhotos": 0
}
```

## Export flow

Route `GET /admin/events/{id}/export` on `App\Controller\Admin\EventController`:
1. `denyAccessUnlessGranted(EventVoter::VIEW, $event)` (admin bypass, else owner).
2. `EventArchiveExporter::export($event)` builds the manifest, opens a temp
   `ZipArchive`, writes `manifest.json`, then streams each `Ready` photo's thumb +
   preview from `photo_thumbs_storage` / `photo_previews_storage`, plus the logo
   from `event_logos_storage` if present.
3. Return `BinaryFileResponse($tmp)` with `Content-Disposition: attachment;
   filename="event-<slug>.zip"` and `deleteFileAfterSend(true)`.
4. `#[Audited(AuditAction::EventExport, targetParam: 'id', targetType: 'Event')]`.

## Import flow

Routes `GET/POST /admin/events/import` on `EventController` (import form + handler):
1. `EventImportType`: a file field + CSRF. If `ROLE_ADMIN`, add an `owner`
   `EntityType` (User, `choice_label: 'email'`) — same pattern as `EventType`.
   Non-admins import under `$this->getUser()`.
2. Validate the upload (present, valid, size cap, opens as a readable ZIP). Parse
   `manifest.json`; reject unknown `format` / `version`.
3. **Slug guard:** `EventRepository::findOneBySlug($manifest->event->slug)`. If it
   exists → refuse (flash error naming the slug + redirect back), no rename. The
   `uniq_events_slug` DB constraint is the backstop.
4. Validate every manifest photo has both bundled derivative files and they are
   JPEG. Read entries **only** by manifest-derived names (zip-slip safe — never
   trust arbitrary archive paths).
5. In one DB transaction (`EventArchiveImporter::import($manifest, $zip, $owner)`):
   - Create `Event` (owner = target user or importer), set scalars + `StyleSettings`
     + preserved publish state + logo filename; flush to get the new id.
   - Per photo: `new Photo($event, $contentHash, $originalFilename, $byteSize)` →
     `markReady($takenAt ?? $createdAt, $width, $height, $derivativeBytes)`; flush
     for the id; write thumb + preview to `photo_thumbs_storage` /
     `photo_previews_storage` at `event-<newId>/<newPhotoId>.jpg`.
   - Per subscription: `new EventNotificationSubscription($event, $email)`; set
     status/timestamps; **regenerate tokens**.
   - Write logo bytes to `event_logos_storage` under a fresh Vich filename.
6. Commit. On any failure: roll back the transaction **and** delete any image files
   already written (best-effort cleanup) so the instance is left as before.
7. `#[Audited(AuditAction::EventImport, ...)]`; redirect to the new event's edit page
   with a success flash summarising counts (photos imported, skipped, subscribers).

## Files

**Create**
- `src/Service/Event/EventArchiveExporter.php` — manifest build + ZIP assembly.
- `src/Service/Event/EventArchiveImporter.php` — transactional reconstitution.
- `src/Service/Event/EventArchiveManifest.php` — `FORMAT` / `VERSION` constants,
  serialize/deserialize + validation (throws a typed exception on bad input).
- `src/Form/EventImportType.php` — upload field + admin owner selector.
- `templates/admin/event/import.html.twig` — upload form.
- Tests: `tests/Unit/Service/Event/EventArchiveExporterTest.php`,
  `EventArchiveImporterTest.php`; `tests/Functional/Admin/EventExportTest.php`,
  `EventImportTest.php`.

**Modify**
- `src/Controller/Admin/EventController.php` — add `export()` + `import()` actions.
- `src/Audit/AuditAction.php` — add `EventExport = 'event.export'`,
  `EventImport = 'event.import'` (category() already maps `event.*` → "Event").
- `templates/admin/event/index.html.twig` (+ edit) — Export button; Import link.

**Reuse (no change):** `EventVoter`, `EventRepository::findOneBySlug`,
`UserRepository`, the four Flysystem `FilesystemOperator` disks, the `EntityType`
owner-selector pattern from `EventType`, `Photo::markReady`,
`EventNotificationSubscription` constructor + token generation, the
`App\Audit\Attribute\Audited` attribute + `AuditContext`.

## Error handling

- Corrupt/non-ZIP upload, missing/invalid `manifest.json`, unknown format/version,
  a manifest photo whose derivative files are absent, oversized upload → reject with
  a user-facing flash; no partial event created.
- Slug already exists → refuse with an explicit message naming the slug.
- Mid-import failure → transaction rollback + written-file cleanup.

## Verification

- **Unit:** exporter produces a manifest matching a fixture event; importer refuses
  a colliding slug; importer regenerates tokens (imported ≠ source); publish state
  preserved; only `Ready` photos exported (`skippedPhotos` counted).
- **Functional:** `GET .../export` returns a ZIP attachment containing
  `manifest.json` + the expected derivative entries; `POST .../import` of that ZIP
  (clean DB) creates an event owned by the importer with the right photo count and
  subscribers; admin import honours the owner selector; colliding-slug import is
  refused with the event count unchanged; a non-organizer is denied.
- **Roundtrip:** export event A → (rename/clean) → import → A′ is equivalent
  (settings, style, publish state, photo derivatives servable at `/e/<slug>/p/...`,
  subscriber list + statuses).
- `vendor/bin/grumphp run` green (phpstan L10, phpcs, rector, `schema:validate`).

## Out of scope

- Banner image (feature #93 not merged; trivial follow-up once it lands).
- Collection export (the "up" direction — separate ticket).
- Async/large-archive delivery (revisit if a huge event times out synchronously).
- Cross-instance user reconciliation beyond owner assignment.
