# #109 — Indexable Attribute Boundary (privacy policy spec)

**Status:** boundary approved; technical build deferred to a follow-on spec.
**Date:** 2026-07-15
**Issue:** [#109 — Image recognition: tag clothing in photos for search](https://github.com/jwderoos/eventPhotos/issues/109)

## Purpose

Issue #109 proposes using image recognition to auto-tag photos so visitors can
find themselves in a large event gallery without face recognition (e.g. "I wore
an orange shirt"). During brainstorming the scope was widened to ask whether
**bib numbers** (race numbers worn by marathon/walking participants) may also be
extracted and made searchable — and, by extension, what the general rule is for
indexing other information visible in a photo.

This document defines **which attribute categories may be extracted and indexed,
and the test that governs that list.** It is deliberately a *boundary* spec: it
does not choose an extraction engine, a tags storage schema, or a search UX.
Those belong to the follow-on build spec that implements #109.

## Governing test

A candidate attribute category is indexable **only if one of the following
holds**:

- **(A) Non-identifying.** The attribute cannot single out a person on its own
  (e.g. clothing colour, garment type, scene/action). Always allowed.
- **(B) Identifying-but-conditioned.** The attribute can link to a specific
  person, *and* **both** of these are true:
  1. a **lawful basis** exists for the processing, and
  2. the processing is within participants' **reasonable expectation** for this
     kind of event.

  Allowed only while **both** conditions hold. If either lapses, the category
  must stop being indexed.

Any attribute that is identifying but lacks both conditions is **out of bounds**.

### Rejected framing (recorded deliberately)

The originating hypothesis for this brainstorm was: *"bib-number extraction is
commonly done and therefore allowed; from that it follows that any other
**non-biometric** information may also be indexed and searched."*

The **"non-biometric ⇒ allowed"** rule is **rejected** and recorded here as
rejected so future work does not drift back to it. Reason: "non-biometric" is
too permissive. It green-lights data that is non-biometric yet clearly
identifying and *not* expected to be indexed — background license plates, house
numbers, names on banners. The dividing line is **not** biometric vs.
non-biometric; it is the governing test above (identifying? and if so, lawful +
expected?).

Bib numbers pass the test — not because they are non-biometric, but because
identity-linkage via a bib is *expected and customary* at races and rests on a
lawful basis the organizer holds. This makes a bib a **conditioned** case, not a
precedent for indexing arbitrary non-biometric content.

## The allowlist

| Category | Class | Status |
|---|---|---|
| Clothing colour | A — non-identifying | Allowed, unconditional |
| Clothing type / garment | A — non-identifying | Allowed, unconditional |
| Scene / action (finish line, water station, running/jumping) | A — non-identifying | Allowed, unconditional |
| **Bib number** | B — identifying, conditioned | Allowed **only** under the bib controls below |

No other category is indexable without amending this spec.

## Bib-number controls

Bib numbers are personal data: a bib is a pseudonymous identifier that maps to a
named registrant via the organizer's registration/timing list. Indexing them is
therefore gated:

- **Per-event toggle, OFF by default.** Bib indexing is enabled per event by the
  organizer. Non-identifying categories (colour, type, scene/action) are
  unaffected by this toggle and may index regardless.
- **Attestation on enable.** When enabling, the organizer affirms that their
  event terms / registration basis cover photo bib-search (they own the bib→name
  mapping and the participant relationship; the platform does not). The
  enable/disable action is recorded in the existing audit log
  (`AuditLogEntry`).
- **Lawful basis is the organizer's.** The platform provides the mechanism and
  the gate; the lawful basis and reasonable-expectation justification are the
  organizer's to hold, per event.

## Objection / suppression (right to object)

Bib search converts "your number was visible in a crowd" into "anyone who knows
your number can enumerate every photo of you." That aggregation is exactly what
triggers a data subject's right to object. The boundary therefore requires:

- **Organizer de-index.** The organizer can remove a bib from the index on
  request.
- **Per-event suppress-list.** A de-indexed bib is added to a per-event
  suppress-list that **survives re-ingest** (#112 regenerates derivatives from
  retained originals) so regeneration never re-adds a suppressed bib.
- **Photo deletion remains separate.** Deleting the photo entirely is the
  blunter, already-supported option and is independent of bib suppression.
- **Launch gate.** Bib-search **may not ship** until this removal path exists.
  A build that indexes bibs without a working suppress path does not satisfy
  this boundary.

## Extraction-engine constraints

The engine is chosen in the build spec, but any engine must satisfy these hard
constraints:

- **No biometric or facial processing** of any kind — self-hosted or external.
  The "no faces / no identity inference" promise must be verifiably true of the
  chosen engine (e.g. face-detection features provably disabled if the engine
  offers them).
- **No third-party retention.** No processor may retain photos beyond the
  processing call.
- **EU region + signed DPA** if extraction is performed by an external
  processor.

Engine choice (self-hosted model vs. vetted third-party API) is **deferred to
the build spec** and must be made against the constraints above.

## Out of scope / prohibited

The following are explicitly **not** indexable and must not be extracted:

- Faces and any biometric identifier.
- Age, gender, or any demographic inference.
- Background text and signage (banners, sponsor boards, names).
- License plates.
- Any attempt to infer a person's identity beyond a bib number under §
  "Bib-number controls".

## Non-goals of this spec

This document intentionally does **not** decide:

- The extraction engine / model.
- The tags storage schema (how attributes attach to a `Photo`).
- The search UX (free-text vs. structured attribute filters).

Those are the follow-on build spec that implements the searchable-tagging
feature of #109 within this boundary.

## Downstream obligations checklist (for the build spec)

- [ ] Non-identifying categories (colour, type, scene/action) index without a
      per-event gate.
- [ ] Bib indexing is per-event, OFF by default, with organizer attestation
      recorded in the audit log.
- [ ] A per-event bib suppress-list exists and survives re-ingest (#112).
- [ ] Bib-search is not launched before the suppress path works (launch gate).
- [ ] The chosen extraction engine performs no biometric/face processing, no
      third-party retention, and — if external — runs in-EU under a DPA.
- [ ] No category outside the allowlist is extracted.
