# Unified free-text photo search (public event gallery)

**Issue:** #117 (bib/attribute search UX)
**Date:** 2026-07-18
**Status:** Approved — ready for implementation plan

## Problem

The public event photos page (`/e/{slug}/photos`) exposes three separate search
controls stacked on the page: a row of colour checkboxes, a row of garment
checkboxes, and a bib-number text field, combined with strict AND logic. It
looks cluttered and doesn't match how a race visitor thinks ("I'm bib 1234, I
wore a red shirt"). We want a single search box: type keywords and/or a bib
number, get matching photos.

## Goals

- One free-text search box replaces the colour/garment/bib controls.
- A visitor can type any mix of bib number, clothing colour, garment, and scene.
- Robust to natural typing (synonyms, multi-word terms, minor typos).
- Visible feedback: recognized terms as chips, unrecognized words flagged.
- Shareable, human-readable URLs; works without JavaScript.

## Non-goals (YAGNI)

- Live as-you-type search / live chip preview. Submit-on-Enter only. Chips are
  server-rendered after submit; removing one is a plain link. A Stimulus live
  preview can be added later if desired.
- Alphanumeric bibs (e.g. `A123`). Bib token is digits-only for now.
- Multiple bibs OR'd together. First numeric token is the bib; extra numbers
  are shown as ignored.
- Backward compatibility with the old `colour[]` / `garment[]` / `bib` URL
  params. They are replaced by a single `q` param (feature is unreleased).

## UX / behavior

- Input: `?q=` free text, e.g. `1234 red shirt finish line`. Plain GET form,
  submit on Enter (cache-friendly, no JS required).
- The single box replaces the colour checkboxes, garment checkboxes, and bib
  field. The time-window browse (`Update` + First/Prev/Next/Last nav) is a
  separate "browse mode" shown only when the box is empty — unchanged.
- After submit, recognized tokens render as **chips** below the box:
  `[bib 1234 ×] [red ×] [t-shirt ×] [finish-line ×]`. Unrecognized words render
  as `Ignored: xyz`.
- Chip removal is a **plain server-rendered link** (no JS): each `×` is an `<a>`
  to the same search with that token's source text stripped from `q`. URL stays
  readable/shareable, e.g. `?q=1234+red+shirt`.
- Discoverability: placeholder `e.g. 1234, red, t-shirt, finish line` plus one
  helper line ("Search by bib number, colour, garment, or scene"). The gating
  narrows the placeholder when only bib indexing is enabled (`e.g. 1234`).

## Match semantics

```
results = P_bib  ∪  (P_colour ∩ P_garment ∩ P_scene)
```

- Within each attribute type: OR (`red blue` → red OR blue).
- Across attribute types: AND (`red shirt` → red AND shirt).
- Bib is OR'd against the whole attribute group (widest net — catches photos
  where the bib was recognized but the runner isn't obviously identifiable, and
  photos matching the clothing where the bib was missed).
- Only a bib typed → just `P_bib`. Only clothing → just the attribute group.
  Empty groups never match-all.
- Bib-suppression (`BibSuppression`) `NOT EXISTS` guard stays inside the bib
  term (unchanged de-index behavior).

## Parsing — `App\Service\Photo\PhotoSearchQueryParser` (new)

Server-side and authoritative (single source of truth, unit-tested, no JS
duplication). Signature roughly:

```php
public function parse(string $q, bool $bibEnabled, bool $attributesAvailable): ParsedPhotoQuery
```

`ParsedPhotoQuery` (new, readonly) holds an **ordered** list of recognized
tokens — each with `type` (bib | colour | garment | scene), `canonical` value,
and `sourceText` (the exact substring the visitor typed) — plus a list of
`ignored` words and the resolved `?string $bib`. `sourceText` is what lets chip
removal re-serialize `q` minus one token.

Algorithm:

1. Normalize: lowercase, collapse whitespace, treat `-` and `/` as spaces.
2. **Longest-phrase-first** scan against a synonym→canonical dictionary so
   multi-word vocabulary matches: `long sleeve shirt`, `finish line`, `hat/cap`,
   `hoodie/sweater`, `water station`, `on course`, `crowd/spectators`,
   `medal/podium`.
3. Synonym dictionary (illustrative, finalize during implementation):
   - Colours: canonical = self; `gray→grey`.
   - Garments: `tshirt/tee/tees/t shirt→t-shirt`; `long sleeve/longsleeve→
     long-sleeve shirt`; `coat→jacket`; `hoodie/hoody/sweater/sweatshirt/jumper
     →hoodie/sweater`; `short→shorts`; `pants/trouser→trousers`;
     `hat/cap→hat/cap`; `shirt` (ambiguous) → **both** `t-shirt` and
     `long-sleeve shirt`.
   - Scenes: `finish/finish line→finish-line`; `running/course/on course→
     on-course/running`; `water/water station→water-station`;
     `crowd/spectators/spectator→crowd/spectators`; `medal/podium/medals→
     medal/podium`; `start→start`.
4. Fuzzy fallback for leftover single words: prefix (≥3 chars) / Levenshtein-1
   against single-word colour names (`blu→blue`, `gren→green`). Conservative to
   avoid false positives; applied to colours only.
5. Bib: first all-digits token (`/^\d+$/`), only if `$bibEnabled`. Extra
   numbers → ignored.
6. Gating: emit clothing/scene tokens only if `$attributesAvailable`; emit bib
   only if `$bibEnabled`. Leftover unmatched words → `ignored`.

Serialization back to `q` (for chip-removal links): join the `sourceText`s of
the retained tokens (plus retained ignored words) with spaces. Each chip's
removal href = the search URL built from all tokens except that one.

## Data / query changes

- `App\Repository\Filter\PhotoAttributeFilter`: add `list<string> $scenes`;
  update `isEmpty()` to include scenes.
- `App\Repository\PhotoRepository::searchReady`: rewrite from all-inner-joins to
  **EXISTS subqueries** combined per the OR/AND shape above. This gives correct
  de-duplication without `distinct`, and keeps the bib-suppression `NOT EXISTS`
  scoped inside the bib term. When only some groups are present, absent groups
  drop out of the AND; when neither bib nor attributes are present the method is
  not reached (browse mode).
- `App\Controller\Public\EventController`:
  - `buildFilter`: read `q`, run `PhotoSearchQueryParser`, build the filter from
    the parsed result (respecting the existing bib/attribute gating).
  - `photos`: `$filter->isEmpty()` still selects browse vs. search mode.
  - `renderSearch`: add `scenes` to the search ETag seed; pass the parsed chips,
    ignored words, and per-chip removal URLs to the template.
- Template `templates/public/event/photos.html.twig`: replace the colour/garment
  checkbox fieldsets and bib field with the single box + chips + ignored line.
  Browse-mode time form and nav unchanged.

## Testing

- **Unit** `PhotoSearchQueryParserTest` (bulk of coverage): single/multi-word
  phrase matching, synonyms, ambiguous `shirt`, fuzzy colour matches, bib
  extraction + extra-number handling, ignored words, gating (bib-only,
  attributes-only, neither), and removal re-serialization round-trips.
- **Unit** `PhotoAttributeFilterTest`: scenes in constructor + `isEmpty()`.
- **Integration** `PhotoRepositorySearchTest`: OR semantics (`P_bib ∪ attr`),
  scene filtering, within/across-type AND/OR, bib suppression still applied.
- **Functional** `EventSearchTest`: `?q=` drives results, chips render, removal
  links strip the right token, ignored words shown. `EventFilterVisibilityTest`:
  box visibility gating (attributes and/or bib enabled/disabled).

## Open questions

None blocking. Synonym dictionary contents will be finalized during
implementation and mirrored in tests.