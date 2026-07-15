# #109 — Attribute Tagging + Search (build spec)

**Status:** approved for decomposition into implementation plans.
**Date:** 2026-07-15
**Issue:** [#109](https://github.com/jwderoos/eventPhotos/issues/109)
**Governed by:** `docs/superpowers/specs/2026-07-15-109-indexable-attribute-boundary-design.md` (the *policy* boundary — this build must stay inside it).

## Goal

Implement automatic attribute tagging and search for event photos, strictly
within the boundary allowlist, using a **self-hosted CPU inference service** that
returns **controlled-vocabulary** tags. Visitors can find themselves by what they
wore ("orange shirt") and, for races where the organizer has enabled it, by bib
number — without any face recognition.

## Non-negotiable constraints (from the boundary spec)

- Index only: clothing colour, clothing type, scene/action (non-identifying,
  unconditional); **bib number** (identifying, gated).
- Bib indexing is **per-event, OFF by default, organizer-attested**, audit-logged.
- A per-event **bib suppress-list** honours the right to object and **survives
  re-ingest** (#112). Bib-search must not ship before this removal path exists
  (**launch gate**).
- Engine performs **no biometric/face processing**, keeps **no photo retention**,
  and is **self-hosted CPU** (no photo leaves the platform).
- **No free-text/embedding search** — it would implicitly index the whole image
  and permit queries outside the allowlist. Search is over discrete tags only.

## Architecture

Extraction runs on the **preview derivative** (1600px, always retained), so it is
independent of the keep-originals toggle and re-runs cleanly on re-ingest. It runs
as a **follow-up async Messenger message** dispatched after `Photo::markReady()`,
so a recognition failure never blocks a photo reaching `Ready`.

```
upload → ProcessPhoto (existing) → markReady
                                      └→ dispatch ExtractPhotoAttributes(photoId)
ExtractPhotoAttributes handler → AttributeExtractorClient →[HTTP]→ inference service
   → filter to allowlist + bib gate + suppress-list + confidence → PhotoAttribute rows
public event page → tag-filtered photo query
```

## Controlled vocabularies (source of truth for the allowlist)

- **Clothing colours** (fixed, ~12): black, white, grey, red, orange, yellow,
  green, blue, purple, pink, brown, beige.
- **Clothing types** (fixed): t-shirt, long-sleeve shirt, jacket, hoodie/sweater,
  dress, shorts, trousers, skirt, hat/cap.
- **Scene/action** (fixed, v1 optional): start, finish-line, on-course/running,
  water-station, crowd/spectators, medal/podium.
- **Bib**: alphanumeric string; persisted only if `confidence ≥ 0.80`; confidence stored.

Adding a vocabulary term is a spec change; the inference service and the search UI
both read from this list.

## Inference service contract (CPU, self-hosted)

- `POST /extract` — request body: preview-derivative image bytes.
  Response JSON:
  ```json
  {
    "clothing_colors": [{"value": "orange", "confidence": 0.92}],
    "clothing_types":  [{"value": "t-shirt", "confidence": 0.88}],
    "scenes":          [{"value": "finish-line", "confidence": 0.71}],
    "bibs":            [{"value": "1423", "confidence": 0.95}]
  }
  ```
  Every `value` MUST be from the vocabularies above (service enforces; Symfony
  side re-validates and drops anything unknown).
- `GET /health` — readiness/liveness.
- **No photo retention; no face/biometric code paths.**
- Internals (not part of the contract, swappable): zero-shot CLIP/FashionCLIP for
  clothing/scene against the fixed prompts; YOLO bib-detector → PaddleOCR for bibs.

## Data model

- **`PhotoAttribute`** — ManyToOne `Photo` (`onDelete: CASCADE`); `type` enum
  (`clothing_color | clothing_type | scene | bib`); `value` (string); `confidence`
  (nullable float). Indexes: `(photo_id, type)`, `(type, value)`.
- **`BibSuppression`** — ManyToOne `Event` (`onDelete: CASCADE`); `bibNumber`
  (string); `createdAt`. Unique `(event_id, bib_number)`. Independent of photos so
  it survives re-ingest.
- **`Event.bibIndexingEnabled`** — bool, default false.

All schema changes via `bin/console doctrine:migrations:diff` (never hand-written).

## Bib gate + suppression semantics

A bib value is written as a `PhotoAttribute` **only if all** hold:
1. `event.bibIndexingEnabled === true`,
2. the value is **not** present in `BibSuppression` for the event,
3. `confidence ≥ 0.80`.

De-index (organizer action): delete matching bib `PhotoAttribute` rows for the
event and insert a `BibSuppression` row — so re-extraction (including re-ingest)
never re-adds it. Colour/type/scene tags are never gated.

## Search UX (v1)

Structured filters on the public event page: colour + garment multi-select
(always available), plus a bib-number field shown **only when**
`bibIndexingEnabled`. Filters map to SQL JOINs on `PhotoAttribute`, layered on the
existing time-window photo query. No free-text search. The public route keeps the
anonymous no-session invariant (see CLAUDE.md).

## Testing strategy

- Bind a **fake inference client** under `when@test` (mirror
  `App\Tests\Fake\FakeGoogleOAuthClient`) so tests never hit the network.
- Unit: bib gate truth table (toggle × suppression × confidence); vocabulary
  validation drops unknown values; `BibSuppression` uniqueness.
- Integration (`dama/doctrine-test-bundle`): extraction handler idempotency
  (re-run replaces tags); suppress-list honoured across a simulated re-ingest.
- End-to-end: upload → tags stored → search returns photo → de-index removes it
  and it stays gone after re-ingest.

## Decomposition (implementation plans)

- **Plan A** — Bib governance + suppress-list (Symfony-only, no ML dep). Launch-gate
  prerequisite; fully testable now.
- **Plan B** — CPU inference microservice + `AttributeExtractorClient` + test fake.
- **Plan C** — Extraction pipeline (`ExtractPhotoAttributes`) + `PhotoAttribute` storage,
  wired into `ProcessPhotoHandler` and re-ingest.
- **Plan D** — Public attribute search UX.

Sequence: **A → B → C → D**. Bib-search UI (Plan D) is gated on Plan A.

## Out of scope (this build)

Free-text/semantic search, pgvector, GPU acceleration, demographic/scene inference
beyond the fixed vocabulary, and any attribute outside the boundary allowlist.
