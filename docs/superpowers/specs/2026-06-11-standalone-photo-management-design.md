# Standalone photo management UI — design

GitHub issue: [#20](https://github.com/jwderoos/eventPhotos/issues/20)

Move photo management out of the event-edit form into a dedicated page reachable from the event list, switch the grid to a data table with metadata columns, restructure the upload queue panel, and fix the bulk-upload refresh quirk so new uploads appear in the table as each upload completes — not only after the batch finishes.

## Goals & non-goals

**Goals**

- A standalone `/admin/events/{id}/photos` page that owns photo upload, review, and per-photo actions.
- A data-table view of existing photos with thumbnail, filename, taken-at, uploaded-at, dimensions, file size, status, and actions.
- A fixed-height scrolling upload queue panel above the table, ordered uploading → pending → done.
- Per-upload feedback: a newly uploaded photo appears in the table immediately on `202`, without waiting for the rest of the batch to finish.
- Server stays the source of truth for row markup (no JS-side `<tr>` template).

**Non-goals**

- No changes to the photo ingest pipeline (upload validation, worker, derivative generation, EXIF read).
- No changes to the public photo serve endpoints.
- No column sorting — uploaded-at DESC is the only order.
- No client-side filtering or search on this page.
- No bulk select / bulk delete in this iteration.

## Routes

| Method | Path | Route name | Controller method | Purpose |
|---|---|---|---|---|
| GET | `/admin/events/{id}/photos` | `admin_event_photo_manage` | `Admin\PhotoController::manage` | Standalone photo management page (new). |
| POST | `/admin/events/{id}/photos` | `admin_photo_upload` | `Admin\PhotoController::upload` | Existing upload endpoint — unchanged URL and method, response body extended (see below). |
| GET | `/admin/events/{id}/photos-grid` | `admin_photo_grid` | `Admin\PhotoController::gridFrame` | Existing turbo-frame fragment — now accepts `?page=N`. |
| POST | `/admin/events/{eventId}/photos/{photoId}/retry` | `admin_photo_retry` | unchanged | Redirect target changes to `admin_event_photo_manage`. |
| POST | `/admin/events/{eventId}/photos/{photoId}/delete` | `admin_photo_delete` | unchanged | Redirect target changes to `admin_event_photo_manage`. |

Symfony matches by method, so `GET` and `POST` can share the same path; no upload-URL churn.

Access control on the new GET route: `denyAccessUnlessGranted(EventVoter::EDIT, $event)`, same pattern as today.

## Page structure

New template: `templates/admin/event/photos_manage.html.twig`, extends `admin/_base.html.twig`.

Vertical layout:

1. **Header.** Title `Photos · {{ event.name }}`. Breadcrumb `Admin → Events → {{ event.name }} → Photos`. A "Back to event" link to `admin_event_edit`.
2. **Uploader card.** Hosts the `data-controller="photo-uploader"` element (formerly `uppy`). The controller injects the dropzone and the queue list. Queue list is rendered as a `max-h-64 overflow-y-auto` scrollable container so a large batch doesn't push the table off-screen.
3. **Table card.** Full-width below the uploader. Wraps a single `turbo-frame id="photos-grid"` that lazy-loads `admin_photo_grid`. Inside the frame: data table + pagination footer.

Event list template `templates/admin/event/index.html.twig` gets a new per-row action button:

> QR · Photos · Edit · Delete

"Photos" links to `admin_event_photo_manage`.

`templates/admin/event/form.html.twig` removes the `{% include 'admin/event/photos_panel.html.twig' %}` block in `mode == 'edit'`. The file `templates/admin/event/photos_panel.html.twig` is deleted.

## Upload queue panel

Lives inside the uploader stimulus controller. The container holds three `<ul>` children, rendered top to bottom:

1. **Uploading** — XHR in flight; progress bar live (0–100%).
2. **Queued** — picked but not started, waiting on `CONCURRENCY = 3`.
3. **Done** — completed: success ("Uploaded"), duplicate ("Already uploaded"), or error (`progress-error` + reason).

Each item is created in `queued`, then moved between lists as it transitions `queued → uploading → done`. Sub-section headers ("Uploading · N", "Queued · N", "Done · N") are hidden when their list is empty.

A "Clear done" button appears next to the Done header once at least one item is in done; it clears only the done list.

Queue is per-page-load; refreshing the page wipes it. No server-side queue persistence.

## Photo table

Re-render `templates/admin/event/photos_grid.html.twig` as a `<table class="table table-zebra">` with columns:

| Thumb | Filename | Taken at | Uploaded at | Dimensions | Size | Status | Actions |
|---|---|---|---|---|---|---|---|

Per-row markup lives in a new partial `templates/admin/event/_photo_row.html.twig`:

```twig
<tr data-photo-id="{{ photo.id }}" data-status="{{ photo.status.value }}">
  ...
</tr>
```

Cell rules:

- **Thumb** — 48×48 `<img loading="lazy">` for `ready`; muted placeholder for `pending`/`failed`.
- **Filename** — truncated with `title="{{ photo.originalFilename }}"`.
- **Taken at** — `photo.takenAt|date('Y-m-d H:i', event.timezone)`; blank when null.
- **Uploaded at** — `photo.createdAt|date('Y-m-d H:i', event.timezone)`.
- **Dimensions** — `{{ photo.width }} × {{ photo.height }}` for `ready`; blank otherwise.
- **Size** — human-formatted byte size (`format_bytes` Twig filter; add a small Twig extension if it does not already exist).
- **Status** — colored badge: ready=success, pending=info, failed=error. Failed badge has `title="{{ photo.processingError }}"`.
- **Actions** — inline forms: Retry (only on `failed`, with CSRF), Delete (always, with CSRF), View (link to `photo_serve_preview` in a new tab).

The full table includes this partial in a loop. The upload controller appends a server-rendered instance of this same partial as the per-upload placeholder (see "Per-upload refresh" below).

Stale-pending warning (the existing `hasStalePending` alert) is preserved above the table, unchanged.

## Pagination

- Constant `Admin\PhotoController::PER_PAGE = 100`.
- `PhotoRepository::paginateForEvent(Event $event, int $page, int $perPage): array{photos: list<Photo>, total: int}` — a single `COUNT()` and a paginated `findBy` ordered by `createdAt` DESC.
- `gridFrame()` reads `?page=N`, clamps to `max(1, (int) $page)`, and passes `photos`, `total`, `page`, `perPage` to the template.
- Pagination footer inside the turbo-frame: prev/next links + "Showing X–Y of Z". Links target `#photos-grid`, so navigation swaps in-place.
- Past last page → empty `<tbody>` + "No photos on this page" notice; prev link still works.

The `GRID_LIMIT = 200` constant is removed; pagination replaces the cap.

## Per-upload refresh

Two coordinated changes:

1. **Server.** `Admin\PhotoController::upload`'s 202 JSON response is extended:

   ```json
   { "status": "pending", "photoId": 123, "rowHtml": "<tr ...>...</tr>" }
   ```

   `rowHtml` is `$this->renderView('admin/event/_photo_row.html.twig', {event, photo})`. The 200 `duplicate` response is unchanged (the row already exists in the table).

2. **Client.** The `photo-uploader` stimulus controller, on 202:
   - Moves the queue item to "Done · Uploaded".
   - Parses `rowHtml` and **prepends** the `<tr>` to the `<tbody>` inside `#photos-grid`.
   - If the current page is not page 1, skips the DOM insertion. The row will appear after pagination navigation; the 5s poller will redraw the page anyway.

The existing `photos-poller` continues to drive `pending → ready` transitions. The placeholder `<tr>` carries `data-status="pending"`, so the poller schedules its 5s refresh immediately. Each refresh re-renders the current page from the server, replacing the placeholder with the real `ready` row (or marking it `failed`).

The renamed stimulus controller becomes `assets/controllers/photo_uploader_controller.js`; the `data-controller` attribute and `*-value` keys change from `uppy-*` to `photo-uploader-*`. The internal value names (`endpoint`, `grid-frame`) stay the same.

## Security

- `manage` and `gridFrame` both call `denyAccessUnlessGranted(EventVoter::EDIT, $event)`.
- Upload / retry / delete keep their existing voter + CSRF gates; CSRF token IDs unchanged.
- Pagination param is cast to `int` and clamped to `max(1, $page)`.

## Error handling

- Upload non-2xx → queue item goes to Done with `progress-error` + reason; no row appended.
- Upload 200 `duplicate` → queue item goes to Done with "Already uploaded"; no append (duplicate already in the table).
- Worker failure → existing `Photo::markFailed` path; poller picks it up, the table row re-renders with `data-status="failed"` and a Retry button.
- Empty event → table renders with empty body and "No photos yet. Drop some files above." (same copy as today, relocated to the new page).
- Retry / delete redirect target changes from `admin_event_edit` to `admin_event_photo_manage`. Page number is not preserved across redirects; user lands on page 1.

## Components & files

**New**

- `templates/admin/event/photos_manage.html.twig` — standalone page.
- `templates/admin/event/_photo_row.html.twig` — single `<tr>` partial (also used as the upload placeholder).
- `assets/controllers/photo_uploader_controller.js` — renamed and extended from `uppy_controller.js`.
- `tests/Functional/Admin/PhotoManagePageTest.php`
- `tests/Functional/Admin/PhotoUploadResponseTest.php`
- `tests/Functional/Admin/PhotoPaginationTest.php`
- `src/Twig/BytesExtension.php` — a minimal `format_bytes` Twig filter (KB/MB, one decimal). No existing helper in the codebase.

**Modified**

- `src/Controller/Admin/PhotoController.php` — adds `manage()`; `upload()` returns `rowHtml`; `gridFrame()` paginates; `retry()` and `delete()` redirect to `admin_event_photo_manage`.
- `src/Repository/PhotoRepository.php` — adds `paginateForEvent()`.
- `templates/admin/event/index.html.twig` — adds a Photos action per row.
- `templates/admin/event/form.html.twig` — removes the photos panel include.
- `templates/admin/event/photos_grid.html.twig` — rebuilt as a table with pagination footer.

**Deleted**

- `templates/admin/event/photos_panel.html.twig`
- `assets/controllers/uppy_controller.js` (replaced by the renamed file)

## Tests

- **`PhotoManagePageTest`** — GET requires `ROLE_ORGANIZER` + ownership (admin bypass); renders the table headings; shows correct row count for an event with N photos.
- **`PhotoUploadResponseTest`** — POST 202 contains `rowHtml` whose markup includes the new photo's id and a pending badge. Duplicate response remains `{status: "duplicate", photoId}` and contains no `rowHtml`.
- **`PhotoPaginationTest`** — with 150 photos, `?page=1` shows 100 rows, `?page=2` shows 50, `?page=3` is empty. Sort is `createdAt DESC` across pages.
- **`EventListPageTest`** — event list row exposes a Photos action linking to the manage page (add if not already present).
- Existing `Admin/PhotoController` upload/retry/delete tests stay; only the redirect-target assertion in retry/delete tests updates.

No new unit tests for the JS; the repository has no JS test harness today and the contract under test is on the server (`rowHtml` shape, pagination, redirect targets). `dama/doctrine-test-bundle` keeps functional tests transactional.

## Out of scope

- Column sorting.
- Search / filter.
- Bulk select / bulk delete.
- Inline editing of photo metadata.
- Resumable uploads or replacing the custom uploader with the real Uppy library.
