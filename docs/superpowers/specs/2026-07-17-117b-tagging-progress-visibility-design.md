# #117b — Tagging-progress visibility (attribute-extraction state)

**Status:** design approved-in-shape (pending user review of this file).
**Branch:** `feature/117-real-bib-recognizer` (same branch as the bib recognizer;
this is a follow-on within #117 — the branch is not merged until the full
functionality, recognizer + progress visibility, satisfies the organizer).

## Problem

A photo reaches `PhotoStatus::Ready` the moment its **derivatives** are
generated — that's what makes it viewable in the gallery. But bib/tag detection
(`ExtractPhotoAttributes`) is a **separate async step dispatched after** the
photo is Ready, and the system **never records whether that step has run**.

Consequences the organizer hits today:
- A Ready photo showing no tags is ambiguous: tagging still queued? tagging done
  and nothing found? tagging failed? — indistinguishable.
- There is **no progress signal** for a (re)ingest: extraction is minutes/photo
  on CPU, so an organizer who re-ingests an event has no way to see how far along
  the tagging is or when it's finished.

## Goal

Make attribute-extraction (tagging) state **observable** — per photo and as an
event-level progress indicator — without changing what `Ready` means.

## Rejected approach (recorded so we don't drift back)

**"Fold tagging into the `Ready` transition"** (don't mark Ready until tagging
also completes) is **rejected**. `Ready = viewable` is a deliberate, valuable
property, and tagging is:
- **slow** — minutes/photo on CPU; gating Ready would keep photos out of the
  gallery for minutes;
- **best-effort** — `ExtractPhotoAttributesHandler` returns empty and leaves the
  photo Ready on any inference/transport error, so a recognizer hiccup never
  blocks a photo. Gating Ready would regress that resilience;
- **always dispatched but variable in yield** — extraction runs for every Ready
  photo (clothing/scene even when bib indexing is off), but may legitimately
  produce zero tags.

So tagging is tracked as a **separate state**, not merged into `Ready`.

## Design

### Data model — a completion marker on `Photo`

Add `Photo::$attributesExtractedAt` (`?\DateTimeImmutable`, nullable, default
`null`) with:
- `getAttributesExtractedAt(): ?\DateTimeImmutable`
- `markAttributesExtracted(): void` — sets it to "now".
- `isTaggingPending(): bool` — `true` when `status === Ready` **and**
  `attributesExtractedAt === null` (i.e. viewable but not yet tagged).

Semantics: **null = extraction has not completed for the current processing
cycle**; **set = extraction handler ran to completion** (regardless of whether
any tag was found). `resetForReingest()` / `resetForRetry()` set the photo back
to `Pending` and **clear `attributesExtractedAt` to null**, so a re-ingest
correctly re-enters the "tagging pending" state.

Migration generated via `bin/console doctrine:migrations:diff` (never
hand-written, per repo rule); `doctrine:schema:validate` must stay green.

### Handler — set the marker on completion

`ExtractPhotoAttributesHandler::__invoke`: after the existing tag persistence and
`flush()`, call `$photo->markAttributesExtracted()` and flush. Set it on **every
successful completion**, including the "found no bibs/tags" outcome — that is the
whole point (distinguishes "done, nothing found" from "not done").

It is **not** set when the handler returns early or throws before completion
(photo not Ready / preview unreadable / transport error): the timestamp stays
null, the photo keeps showing "tagging…", and Messenger's retry/dead-letter
handles the failure — mirroring today's best-effort behaviour.

### Controller — event-wide progress + stale detection

`PhotoController::gridFrame` computes and passes to the template:
- `readyCount` — event-wide count of Ready photos (`PhotoRepository::countReady`).
- `taggedCount` — event-wide count of Ready photos with `attributesExtractedAt`
  not null (new `PhotoRepository::countTagged(Event): int`).
- `hasStaleTagging` — true when a Ready, still-untagged photo has been waiting
  past a `STALE_TAGGING_THRESHOLD` (`-5 minutes`, mirroring
  `STALE_PENDING_THRESHOLD`). Detection uses the photo's **last-updated**
  timestamp (not `createdAt`), so a re-ingest of old photos does not immediately
  read as stale.
- `processingIncomplete` — true when the event has any `Pending` photo **or**
  `taggedCount < readyCount`. Drives the poller across paginated pages.

### Templates

- `_photo_row.html.twig`: on Ready rows add `data-tagging="{{ pending|done }}"`
  (derived from `photo.isTaggingPending`) and a small badge next to the status
  badge — a "tagging…" (in-progress) indicator vs a "tagged ✓" done marker.
  Pending/Failed rows carry no tagging state.
- `photos_grid.html.twig`: an event-level **progress line** ("Tagging
  {{ taggedCount }} / {{ readyCount }}") shown whenever `readyCount > 0`; a
  `hasStaleTagging` warning banner mirroring the existing `hasStalePending` one
  (e.g. "Some photos are taking a long time to tag. Is the worker / inference
  service running?"); and a frame-level data attribute exposing
  `processingIncomplete` for the poller.

### Poller — extend the "still working" condition

`photos_poller_controller.js` currently reloads the turbo-frame every 5s while a
`[data-status="pending"]` row exists, then stops. Extend the stop condition to
keep polling while **any** of: a `[data-status="pending"]` row exists, a
`[data-tagging="pending"]` row exists, or the frame's `processingIncomplete`
flag is set (the last makes the event-level progress line animate to completion
even when the current page's rows are all done but other pages aren't).

No new polling mechanism, no new dependency — one predicate change plus reading a
data attribute.

## Testing

- **Unit (`PhotoTest`):** `markAttributesExtracted()` sets the timestamp;
  `isTaggingPending()` is true for Ready+null and false for Ready+set / Pending /
  Failed; `resetForReingest()` and `resetForRetry()` clear the timestamp.
- **Integration (`ExtractPhotoAttributesHandlerTest`):** the timestamp is set on
  a successful run (both when tags are found and when none are); it stays null
  when the preview is unreadable (early return) — the photo remains "tagging
  pending".
- **Integration (`PhotoRepository`):** `countTagged` counts only Ready photos
  with the timestamp set.
- **Functional (grid):** the progress line renders `taggedCount / readyCount`;
  a Ready-untagged row carries `data-tagging="pending"` and a Ready-tagged row
  `data-tagging="done"`; the stale banner appears past the threshold; the frame
  exposes `processingIncomplete` when appropriate.
- `doctrine:schema:validate` green (migration matches mapping); full `grumphp`
  gate green.

## Non-goals

- Not changing the meaning of `Ready` or the ingest state machine
  (`Pending → Ready/Failed`).
- Not adding a distinct persisted "tagging failed" status — a stuck-null photo
  past the stale threshold surfaces via the `hasStaleTagging` banner, mirroring
  the existing stale-pending treatment. A first-class extraction-failure state is
  possible later but is out of scope here.
- No change to the HTTP contract, the inference service, or the recognizer.
- No per-photo "re-tag only" action — re-running tagging is via the existing
  re-ingest (which now correctly clears + re-sets the marker).

## References

- Recognizer feature: `docs/superpowers/specs/2026-07-17-117-real-bib-recognizer-design.md`
- Pipeline: `src/MessageHandler/ProcessPhotoHandler.php` (dispatches
  `ExtractPhotoAttributes` after Ready), `src/MessageHandler/ExtractPhotoAttributesHandler.php`
- Grid + poller: `src/Controller/Admin/PhotoController.php::gridFrame`,
  `templates/admin/event/photos_grid.html.twig`, `templates/admin/event/_photo_row.html.twig`,
  `assets/controllers/photos_poller_controller.js`
- Precedent: `hasStalePending` (stale-detection + banner), `STALE_PENDING_THRESHOLD`
