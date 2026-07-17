# Reversible bib de-index, managed from the Tags page

**Issue:** #117 (branch `feature/117-real-bib-recognizer`)
**Date:** 2026-07-17

## Problem

An organizer can de-index a bib number, but the action is destructive and one-way:
`Admin\PhotoController::suppressBib` inserts a `BibSuppression` row **and** hard-deletes
every matching `PhotoAttribute` bib row event-wide (`deleteBibForEvent`). Once done there is
no undo — the underlying data is gone, so removing the suppression alone would restore
nothing. The de-index control also lives on the photo grid, detached from the read-only
Tags overview where the rest of tag inspection happens.

## Goal

Make de-index reversible and move its management to the Tags page:

- **Undo** a de-index instantly and losslessly.
- **De-index** (existing behavior) and see what is currently de-indexed, both from the
  Tags overview.

Scope is **bibs only** — bibs are the one attribute type where value-level, event-wide
de-index is meaningful (a bib identifies one person; privacy/removal is the real use case).
Colours and garments come from a fixed vocabulary and are broad categories (de-indexing one
would nuke an entire category event-wide); scenes have no public filter at all. Those three
stay read-only. See "Rejected alternatives".

## Core pivot: suppression becomes a reversible query-time overlay

Today suppression is destructive (delete the rows). We change it to a **flag checked at read
time**, never mutating the tag data:

- `PhotoAttribute` bib rows are the raw truth — **never deleted** on suppress, and **always**
  written by extraction regardless of suppression state.
- `BibSuppression(event, bibNumber)` is a reversible flag that hides a bib from public search
  and from the "indexed" view, consulted only at read time.

This is what makes undo lossless: un-suppress = delete one `BibSuppression` row → the tags
are visible again immediately.

`BibSuppression` keeps its current shape — no schema change, no rename. (Generalizing to an
`AttributeSuppression(event, type, value)` table is a small migration to defer until/unless
colours or garments ever need de-index; YAGNI.)

## Changes

### 1. Write path — always persist bibs

`MessageHandler\ExtractPhotoAttributesHandler::bibIsIndexable()` stops calling
`BibSuppressionRepository::isSuppressed()`. The confidence threshold (`BIB_MIN_CONFIDENCE`)
and `Event::isBibIndexingEnabled()` gates stay. Result: a bib is persisted even while
suppressed, so a photo ingested during an active suppression stays consistent and reappears
on undo.

### 2. Public search — exclude at query time

`Repository\PhotoRepository::searchReady()` bib branch gains an exclusion so a visitor
searching a de-indexed bib gets **zero** results (not "all photos" — the filter must not be
silently dropped). Enforced in the query so it is correct regardless of caller:

```
AND NOT EXISTS (
    SELECT 1 FROM App\Entity\BibSuppression bs
    WHERE bs.event = :event AND bs.bibNumber = :bib
)
```

(Because suppressed bibs still have `PhotoAttribute` rows, the existing inner join would
otherwise match and return the photos — the explicit `NOT EXISTS` is required.)

### 3. Delete path — remove dead code

`Repository\PhotoAttributeRepository::deleteBibForEvent()` and its call in `suppressBib` are
removed; nothing else uses it.

### 4. Tags page becomes the management home

Write actions move from `Admin\PhotoController` into `Admin\PhotoTagController` (it owns the
page). `PhotoTagController` gains `EntityManagerInterface`, `BibSuppressionRepository`, the
CSRF/audit plumbing it currently lacks.

Overview data: the controller reads `aggregateForEvent($event)` (now includes suppressed
bibs, since rows are no longer deleted) and `suppressedBibNumbers($event)`, then partitions
the bib group:

- **Indexed** bibs = aggregate bibs *minus* suppressed. Rendered as today's chip (links to
  the filtered public gallery) plus a small **De-index** button.
- **De-indexed** bibs = **every** row from `suppressedBibNumbers` (so preemptively de-indexed
  bibs with zero matching photos also appear). Muted chip, count shown when the aggregate has
  one, plus an **Undo** button.

A free-text **"De-index a bib number"** field stays on this page (handles OCR-variant values
and preemptive/privacy de-index of a bib not yet in the list).

Colours / garments / scenes sections: unchanged, read-only.

### 5. Routes / audit / templates

- `admin_bib_suppress` (POST): no longer deletes attribute rows; inserts the `BibSuppression`
  if absent; redirects to the **Tags page** (`admin_photo_tags`). CSRF `suppress_bib_<event>`.
- `admin_bib_reindex` (POST, new): removes the `(event, bibNumber)` suppression row; redirects
  to the Tags page. CSRF `reindex_bib_<event>`.
- New `Audit\AuditAction::EventBibReindex = 'event.bib_reindex'`; `suppressBib` keeps
  `EventBibSuppress`.
- The de-index form is **removed** from `templates/admin/event/photos_grid.html.twig`.

## Testing

- `Integration/MessageHandler/ExtractPhotoAttributesHandlerTest` — flip the existing "skips a
  suppressed bib" case to assert the bib **is persisted** while suppressed (behavior changed).
- `Integration/Repository/PhotoRepositoryTest` — searching a suppressed bib returns empty;
  after removing the suppression the photos are returned.
- `Integration/Repository/PhotoAttributeRepositoryTest` — drop `deleteBibForEvent` coverage.
- `Functional/Admin/BibSuppressionActionTest` — suppress no longer deletes `PhotoAttribute`
  rows; reindex restores search visibility; the grid form is gone; the Tags page renders both
  the indexed and de-indexed bib lists with their controls.

## Rejected alternatives

- **Undo re-extracts / undo = unblock-only** (keeping destructive delete): re-extraction is
  heavy OCR over the whole event to recover one bib and we no longer track which photos had
  it; unblock-only makes undo appear to do nothing. The reversible overlay is lossless and
  instant instead.
- **De-index all four tag types:** scenes have no public filter (de-index affects only the
  admin overview); colours/garments are fixed-vocabulary categories where event-wide
  de-index is a blunt instrument and the real need is per-photo correction (a different
  feature). Not built.
- **Rename `BibSuppression` → `AttributeSuppression` now:** speculative, since only bibs are
  in scope. Deferred.

## Note: pre-existing suppressions

`BibSuppression` rows created under the old destructive code point at bib attributes that
were already hard-deleted — undoing them restores nothing. Lossless behavior applies from
this change forward. This is a feature branch, so no released prod data is expected; no
data-repair migration is written. Documented here rather than handled in code.
