# #117 — Real bib recognizer (Phase 1): person-crop → OCR

**Status:** design approved (pending user review of this file).
**Scope:** Phase 1 (bibs) only. Phase 2 (clothing/colour/scene via CLIP) is a
documented follow-on with its own spec. `StubRecognizer` is retained for the
non-bib fields until then.

## Goal

Replace the bib half of the deterministic `StubRecognizer` behind the inference
service with a real, self-hosted, CPU-only recognizer that extracts **race bib
numbers** from event photos. Everything else in the attribute-tagging pipeline
(DB, async handler, ≥0.80 confidence gate, per-event toggle, suppress-list,
public search, admin overview, bulk retry) already exists and is tested from
#109 — **this work is only the model behind the `Recognizer` protocol**
(`inference/app/recognizer.py`). The HTTP contract and the entire PHP side are
**unchanged**.

## Fixed constraints (inherited, not re-litigated)

- **Self-hosted, CPU-only**, in the TrueNAS Docker stack. No GPU. No photo leaves
  the platform; no third-party retention.
- **In-memory only** — the image and all intermediate crops stay in
  PIL/NumPy buffers and are never written to disk (matches current `main.py`).
- **Contract unchanged:** `POST /extract` (raw JPEG) → `{clothing_colors,
  clothing_types, scenes, bibs}` of `{value, confidence}`. The PHP side keeps the
  ≥0.80 bib gate, per-event toggle, and suppress-list.
- **Boundary (#109, hard):** no faces/biometrics, no landmarks/embeddings, no
  age/gender/demographic inference, no background text/signage/plates, no identity
  inference beyond a bib number.
- **Input is the PREVIEW derivative** (~1280 px long edge), not the original —
  `ExtractPhotoAttributesHandler` reads from `photo_previews_storage`, and
  originals are not always retained. The recognizer must work on the preview.

## Key facts established during brainstorming

- A real event export (`event-race-with-bibs-f1r5c7.zip`, ~37 photos, retain
  originals on) provides the eval corpus.
- **Bibs in this event are personalised: a first NAME above a NUMBER**
  (e.g. "JOOST 2236", "RAF 1428"). We index the **number only** (`2236`) — a
  pseudonymous identifier within the #109 boundary. **The printed first name is
  never extracted or indexed** (indexing it would be directly-identifying PII
  requiring boundary re-approval).
- Bibs are bright cards worn on the front torso/waist, roughly centred on each
  runner — validating a person-detect → torso-crop → OCR approach.
- On the 1280 px preview, foreground/mid-ground bibs are legible; distant runners
  are not (accepted recall loss, precision-first).

## Decisions (from brainstorming)

| Decision | Choice |
|---|---|
| Scope | Phase 1 (bibs) only |
| Extract | Bib **number only**, never the printed name |
| Architecture | Person-detect → torso-crop → OCR → strict bib-number filter |
| Models | YOLOX-Nano/Tiny ONNX (person) + RapidOCR PP-OCRv5 mobile ONNX — **all Apache-2.0** |
| Runtime | `onnxruntime` (CPU). No torch, no Ultralytics/AGPL, no SVHN/RBNR-trained weights |
| Weights delivery | **Baked into the Docker image at build time** (pinned URLs + SHA-256) |
| Dev/test/CI | `RECOGNIZER=stub\|bib` env flag; default `stub` so the fast suite needs no weights |
| Accuracy posture | **Precision-first** (target ≥ ~0.95 of emitted bibs correct on foreground-legible runners; recall secondary) |
| Eval photos | **Not committed** (PII); commit only labels + harness + metrics |
| Prod RAM cap | `mem_limit: 2g` / `cpus: 2` (box has headroom) |

## Architecture

All new code lives under `inference/app/`, behind the existing `Recognizer`
protocol. `main.py` depends only on the protocol and selects the implementation
from an env var — no other change to `main.py`'s request handling.

Small, independently-testable units:

- **`PersonDetector`** — wraps the YOLOX ONNX session. `detect(image) ->
  list[Box]`, filtered to the COCO `person` class above a detection-confidence
  floor. Own letterbox pre-processing + NMS post-processing in NumPy (no torch).
  **Used purely for region-gating — never for identity.**
- **`torso_crop(box, image) -> Image`** — derives the torso sub-region of a
  person box (default: central ~80% width, vertical band ~15%–60% of the box
  height — below head, above knees). Pure geometry, in-memory crop.
- **`TextReader`** — wraps the RapidOCR ONNX pipeline (det + cls + rec).
  `read(crop) -> list[TextLine(text, confidence, box)]`.
- **`BibParser`** — the precision core. `parse(lines, crop_size) ->
  list[Score]`. Applies the bib-number filter (below) and dedup.
- **`BibRecognizer`** — orchestrates detect → crop → read → parse across all
  persons in the frame → `RawResult(bibs=[...])`.
- **`Phase1Recognizer`** — composite implementing `Recognizer`: delegates
  clothing/colour/scene to the existing `StubRecognizer` and bibs to
  `BibRecognizer`. This is what `RECOGNIZER=bib` selects. Phase 2 swaps the stub
  half for CLIP.

### Data flow (one `/extract` call)

```
raw JPEG bytes
  → PIL.Image (in memory, existing main.py)
  → PersonDetector.detect()            → [person boxes]
  → for each box: torso_crop()         → [torso crops]
  → TextReader.read(crop)              → [(text, conf, box), ...]
  → BibParser.parse(lines, crop_size)  → [Score(number, ocr_conf), ...]
  → dedup by value (keep max conf)
  → RawResult(bibs=[...], + stub clothing/colour/scene)
  → main.py: bibs pass through unfiltered (PHP gate is downstream)
```

## The precision core — `BibParser`

Region-gating (OCR only inside torso crops) already removes almost all background
text. `BibParser` is the second line of defence. A candidate string must pass
**all** rules:

1. **Charset:** uppercase `A–Z`, digits `0–9`, at most one internal hyphen.
   Reject lowercase, spaces, punctuation, multi-line.
2. **Must contain ≥1 digit** — drops the all-caps first-name line ("JOOST").
3. **Length bounds:** total length and digit-run length within tunable bounds
   (defaults ~1–8 total, digit run 1–6).
4. **Single token / single line.**
5. **Geometry:** the text box lies within the torso crop, its height ≥ a minimum
   fraction of the crop height (drops tiny/distant text), and it is not jammed
   against a crop edge.
6. **OCR confidence** ≥ an internal floor (below the PHP 0.80 gate; a coarse
   pre-filter).

Returned **`confidence` = OCR recognition confidence** (0–1). The PHP `≥0.80`
gate applies to this value. Same string read from multiple crops → dedup, keep
max confidence.

The regex, length bounds, geometry fractions, and confidence floor are
**documented, tunable module constants**, calibrated against the real eval
photos — not guessed. A default regex (illustrative, to be finalised against the
data): `^[A-Z0-9]{0,4}-?[0-9]{1,6}$` with the "contains a digit" rule.

## Models & sourcing

| Stage | Model | License | Runtime | Notes |
|---|---|---|---|---|
| Person detect | YOLOX-Nano or -Tiny ONNX | Apache-2.0 | onnxruntime | COCO person class; own letterbox+NMS in NumPy |
| OCR (det+cls+rec) | RapidOCR PP-OCRv5 mobile ONNX | Apache-2.0 | onnxruntime | CPU-optimised; charset restricted by post-filter |

- New runtime deps added to `inference/pyproject.toml`: `onnxruntime`,
  `rapidocr-onnxruntime`, `numpy` (Pillow already present). The `test` extra is
  unchanged and still excluded from the runtime image.
- **Dockerfile downloads both models at build time** from pinned URLs with
  **SHA-256 verification**, into the image. No runtime network, no volume, no
  weights in git. Exact per-file URLs/sizes/SHA-256 are pinned during
  implementation (research flagged PP-OCRv5 *mobile* exact byte sizes as
  unverified — confirm on the HuggingFace model card at pin time). Total ≈
  25–45 MB.
- **License hygiene (hard):** never ship Ultralytics YOLOv5/v8/v11 (AGPL-3.0),
  SVHN-trained digit models (non-commercial), or RBNR-trained detectors
  (unsettled commercial rights). All shipped weights are Apache-2.0.

## Recognizer selection + dev/test/CI

- `main.py` builds `RECOGNIZER` from env `RECOGNIZER` (`stub` | `bib`), default
  `stub`.
- Dev/CI and the existing fast `pytest` suite run with `stub` → no multi-model
  weights required to run tests. Prod sets `RECOGNIZER=bib`.
- Real-model tests are gated behind a `@pytest.mark.models` marker that **skips
  when the weights are absent**, so `pytest` stays green without weights. A
  build-time smoke test exercises the real path inside the image.
- Model sessions are constructed once at recognizer init (not per request).

## Deployment (`compose.prod.yaml`)

- Add the `inference` service: `build: ./inference`, `restart: unless-stopped`,
  `stop_grace_period`, the existing `/health` healthcheck, `RECOGNIZER=bib`, and
  resource caps **`mem_limit: 2g` / `cpus: 2`**.
- Add `INFERENCE_SERVICE_URL: "http://inference:8000"` to the `php` and `worker`
  service environments; make `worker` `depends_on` inference.
- Single uvicorn worker (CPU-bound; minutes/photo acceptable). Throughput is
  driven by the PHP messenger worker replicas, each POSTing one image; concurrent
  `/extract` calls serialise on CPU, which is fine at this SLA.
- Deploy is the usual `git pull` → `compose build` → `up` on the NAS. Dev
  `compose.yaml` already wires inference; it gains only the `RECOGNIZER` default
  (stays `stub` in dev unless overridden).

## Testing & eval

- **Unit (no weights):** `BibParser` accept/reject table — name-line rejection,
  numeric and alphanumeric bibs, hyphen handling, lowercase rejection,
  background-text-like strings, length/geometry/confidence edges; torso-crop
  geometry; confidence and dedup behaviour.
- **Integration (weights, skippable):** the full pipeline over a few committed
  **synthetic** bib crops → asserts expected strings. Marked `models`, skipped
  without weights.
- **Eval harness (`inference/eval/`):** runs the full pipeline over the labelled
  real event photos and reports **precision / recall / the false-positive list**.
  - The real photos are **PII and are NOT committed** — they live in a gitignored
    local dir (e.g. `inference/eval/images/`). Committed: the label manifest
    (photo id → expected bib numbers), the harness script, and a metrics summary.
  - **Acceptance:** precision ≥ ~0.95 of emitted bibs correct on the
    foreground-legible subset; recall reported but secondary. Final target
    confirmed once measured; filters tuned toward zero false positives.
- **PHP side:** no changes; existing tests hold. The contract still returns a
  `bibs` list, so `FakeAttributeExtractorClient` and downstream tests are
  unaffected.

## Boundary enforcement (explicit checklist)

- Person detection is **region-gating only** — no face detection, landmarks,
  embeddings, or demographic inference anywhere in the code path.
- **Number only**, never the printed name (BibParser rule 2).
- **No background text** — region-gate + format filter; whole-frame OCR is never
  run.
- **In-memory only** — asserted by test; no temp files.

## Non-goals

Faces, any biometric identifier, demographic inference, background
text/signage, license plates, the printed name, identity inference beyond a bib
number, and Phase 2 (clothing/colour/scene). No PHP-side changes.

## Open items to finalise during implementation

- Pin exact model URLs, SHA-256, and byte sizes (confirm PP-OCRv5 mobile sizes on
  the model card).
- Calibrate `BibParser` regex/bounds/geometry and the OCR-confidence floor against
  the eval photos.
- Confirm measured precision meets the ≥ ~0.95 target; record the metrics summary.

## References

- Pipeline: `docs/superpowers/plans/2026-07-15-109b-inference-service.md`,
  `-109c-extraction-pipeline.md`
- Boundary/policy: `docs/superpowers/specs/2026-07-15-109-indexable-attribute-boundary-design.md`
- Plug point: `inference/app/recognizer.py` (`StubRecognizer` docstring)
- Handler that calls `/extract` (reads the preview):
  `src/MessageHandler/ExtractPhotoAttributesHandler.php`
