# #117 — Real recognizer (Phase 2): clothing colour/type + scene via zero-shot CLIP

**Status:** design approved (pending user review of this file).
**Scope:** Phase 2 (clothing colour/type/scene) only. Replaces the stubbed half
of the recognizer left in place by Phase 1. Bibs are unchanged (Phase 1). The
HTTP contract and the entire PHP side are **unchanged**.

## Goal

Replace the clothing-colour / clothing-type / scene half of the deterministic
`StubRecognizer` (crude average-pixel colour, hardcoded `t-shirt` /
`on-course/running`) with a real, self-hosted, CPU-only **zero-shot** classifier
over the fixed vocabularies in `inference/app/vocabulary.py`. Phase 1 already
ships real bibs and person detection; this work reuses that person-detection +
torso-crop machinery and adds a CLIP-family image encoder scored against
precomputed text embeddings. As in Phase 1, **this is only the model behind the
`Recognizer` protocol** — the `/extract` contract and all PHP code are untouched.

## Fixed constraints (inherited from Phase 1 / #109, not re-litigated)

- **Self-hosted, CPU-only**, in the TrueNAS Docker stack. No GPU. No photo leaves
  the platform; no third-party retention.
- **In-memory only** — the image, all person crops, and all intermediate buffers
  stay in PIL/NumPy memory and are never written to disk (matches `main.py` and
  the Phase 1 no-disk-writes test).
- **Contract unchanged:** `POST /extract` (raw JPEG) → `{clothing_colors,
  clothing_types, scenes, bibs}` of `{value, confidence}`; every non-bib `value`
  filtered to the fixed vocabulary by `main.py`.
- **Input is the PREVIEW derivative** (~1280 px long edge), not the original.
- **Boundary (#109, hard):** person detection is **region-gating only** — no
  faces/biometrics, no landmarks, no embeddings-as-identity, no age/gender/
  demographic inference. Colour/type/scene are non-identifying attributes.

## Key facts established during brainstorming

- **The PHP side applies NO confidence gate to clothing/type/scene.**
  `ExtractPhotoAttributesHandler` persists *every* colour/type/scene the
  recognizer returns (only bibs have the ≥0.80 gate + toggle + suppress-list).
  Therefore **emission policy inside the recognizer is the entire precision
  story** — whatever it emits becomes a public search facet.
- **Race photos are multi-person.** A finish-line frame holds many runners in
  many colours, so whole-image "clothing colour" is near-meaningless. Phase 1
  already produces person boxes + torso crops; Phase 2 reuses them so clothing is
  attributed **per person** and unioned per photo.
- **The vocabulary is fixed and tiny** (12 colours, 9 types, 6 scenes). Text-side
  CLIP embeddings can therefore be **precomputed once** and shipped — the text
  encoder never needs to exist at build or runtime.
- Zero-shot CLIP is **weak at colour naming** relative to garment/scene semantics.
  Per-field floors are independently tunable so colour can be tightened alone if
  eval shows it is noisy; a pixel-based dominant-palette colour path is a
  documented fallback (not built in v1).

## Decisions (from brainstorming)

| Decision | Choice |
|---|---|
| Scope | Phase 2 (clothing colour/type/scene) only; bibs unchanged |
| Attribution unit | **Per detected person** (reuse Phase 1 torso crops); union + dedup per photo. Scene is whole-image. |
| Model role | Zero-shot CLIP-family **image encoder** scored vs **precomputed text embeddings** |
| Extensibility | Per-field classifier **registry** referencing named image encoders — adding/routing a second model (e.g. FashionCLIP for clothing) is config, not a rewrite. **Explicit user directive.** |
| Default model | OpenAI **CLIP ViT-B/32** image encoder ONNX (MIT), int8-quantised (~90 MB) |
| Text embeddings | **Precomputed, committed as JSON**; regenerated deliberately via a committed script (transformers venv, not in the runtime image) |
| Emission policy | **Top-k + margin + per-field floor**, per crop per field: keep labels where `p ≥ FLOOR` and `(p_top − p) ≤ MARGIN`, capped at `k`; union across people, dedup keep-max-conf |
| Accuracy posture | **Recall-lean** — tune floors/margins toward precision ≈ 0.70; recall reported/secondary |
| Selection flag | `RECOGNIZER=stub \| bib \| full`; prod moves to `full` |
| Weights delivery | Image encoder **baked into the image at build** (pinned URL + SHA-256 + byte size, exactly like Phase 1's YOLOX) |
| Dev/test/CI | Fast suite runs `stub` (no weights); real path gated behind `@pytest.mark.models` |
| Prod RAM cap | Unchanged: `mem_limit: 2g` / `cpus: 2` |

## Architecture

All new code lives under `inference/app/`, behind the existing `Recognizer`
protocol. `main.py`'s request handling is unchanged; it already vocab-filters
colour/type/scene and selects the implementation from `RECOGNIZER`.

Small, independently-testable units:

- **`ClipImageEncoder`** — wraps a CLIP image-encoder ONNX session.
  `encode(image: Image) -> np.ndarray` returns the L2-normalized image embedding.
  Owns its preprocessing (resize/center-crop/normalize to the model's expected
  input). Constructed **once** at init. Identified by a name so multiple encoders
  can coexist (extensibility).
- **`ZeroShotClassifier`** — one per attribute field. Holds that field's label
  list + precomputed text-embedding matrix + a reference to an image encoder.
  `classify(image_embedding: np.ndarray) -> list[Score]`: cosine similarity →
  temperature-scaled softmax → **top-k + margin + floor** filter → `list[Score]`.
  The filter thresholds are documented, tunable module constants (per field).
- **Classifier registry** — `dict[field -> ZeroShotClassifier]`. v1 wires all
  three fields to classifiers that share **one** `ClipImageEncoder`. Adding
  FashionCLIP later = register a second encoder, repoint the clothing-field
  classifiers, ship its JSON embeddings — **no pipeline change**.
- **`ClothingSceneRecognizer`** — orchestrates. Reuses Phase 1's `PersonDetector`
  and `torso_crop`:
  - For each detected person: `torso_crop` → `ClipImageEncoder.encode` **once** →
    run the colour and type classifiers on that single embedding (shared, not
    re-encoded per field).
  - Union colour/type results across all people, dedup by value keeping max
    confidence.
  - Encode the **whole image once** → scene classifier.
  - Returns `RawResult(clothing_colors=…, clothing_types=…, scenes=…)`.
- **`CompositeRecognizer`** — refactors the existing `Phase1Recognizer` into a
  general composite of two components: a **bib component** and a
  **clothing/scene component**, each independently real-or-stub. Implements
  `Recognizer`; merges the two `RawResult`s. This removes Phase-named class sprawl
  and is what makes `stub`/`bib`/`full` clean to express.

### Recognizer selection (`main.py`, `build_recognizer`)

| `RECOGNIZER` | Bibs | Clothing/scene | Use |
|---|---|---|---|
| `stub` | none (stub) | stub | dev / CI / fast suite (no weights) |
| `bib` | real (Phase 1) | stub | intermediate / Phase 1 parity |
| `full` | real | **real (Phase 2)** | **prod** |

Built as compositions:
- `stub` → `StubRecognizer` (unchanged).
- `bib` → `CompositeRecognizer(bibs=BibRecognizer, clothing_scene=StubRecognizer)`.
- `full` → `CompositeRecognizer(bibs=BibRecognizer, clothing_scene=ClothingSceneRecognizer)`.

Model sessions (`PersonDetector`, `TextReader`, `ClipImageEncoder`) are built
once at recognizer init, never per request.

### Data flow (one `/extract` call, `RECOGNIZER=full`)

```
raw JPEG bytes
  → PIL.Image (in memory, existing main.py)
  → BibRecognizer.recognize_bibs()                    → [bib Scores]   (Phase 1)
  → PersonDetector.detect()                           → [person boxes]
  → for each box: torso_crop() → ClipImageEncoder.encode()  → crop embedding
        → colour classifier(embedding)  → [colour Scores]
        → type   classifier(embedding)  → [type Scores]
  → union colour/type across people, dedup (max conf)
  → ClipImageEncoder.encode(whole image) → scene classifier → [scene Scores]
  → CompositeRecognizer merges → RawResult(bibs, colours, types, scenes)
  → main.py: colour/type/scene vocab-filtered; bibs pass through (PHP gate downstream)
```

## The precision core — emission policy

Softmax always ranks *something* top, so a floor + margin is the only precision
lever, and (per the PHP no-gate fact) it lives entirely here.

Per crop (colour, type) and per whole-image (scene), for each field:

1. Cosine similarity of the image embedding against every label's text embedding.
2. Temperature-scaled softmax over the field's labels → per-label probability.
3. Keep labels where `p ≥ FLOOR[field]` **and** `(p_top − p) ≤ MARGIN[field]`.
4. Cap at `k[field]` (highest-p first).
5. `confidence = p` (the softmax probability).

Across people: union the per-crop results and dedup by value, keeping max
confidence. `FLOOR`, `MARGIN`, and `k` are per-field documented module constants,
**calibrated against the eval corpus**, tuned recall-lean (precision ≈ 0.70).
Colour's floor is tunable independently so it can be tightened without starving
type/scene.

## Prompt ensembling (text-embedding generation)

Text embeddings are generated offline by `scripts/build_text_embeddings.py`
(requires `transformers`/`torch` in a throwaway venv — **never** in the runtime
image or `pyproject` runtime deps) and committed as JSON.

- Each label is embedded via an **ensemble of prompt templates**, then averaged
  and L2-normalized (standard CLIP zero-shot practice), e.g.:
  - colours: `"a photo of a person wearing {label} clothing"`,
    `"a runner in {label}"`, `"{label} clothing"`.
  - types: `"a photo of a person wearing a {label}"`, `"a {label}"`.
  - scenes: `"a photo of the {label} at a running race"`, `"{label}"`.
- Output: `inference/app/text_embeddings/clothing_colors.json`,
  `clothing_types.json`, `scenes.json` — each `{model, dim, labels: [...],
  embeddings: [[...], ...]}`. Small (~110 KB total), human-diffable.
- **Must match the shipped image encoder's embedding space** (same model family).
  The JSON records the model id + dim; the loader asserts `dim` matches the
  image encoder and that `labels` are a subset of `VOCABULARY[field]`.
- Regeneration is a deliberate, documented step (in the script header + spec),
  not part of CI or the Docker build.

## Models & sourcing

| Stage | Model | License | Runtime | Notes |
|---|---|---|---|---|
| Clothing/scene image embed | CLIP ViT-B/32 image encoder ONNX (int8) | MIT | onnxruntime | Own preprocessing in NumPy/PIL; text encoder NOT shipped |

- **Runtime deps unchanged:** `onnxruntime`, `numpy`, `pillow` are already in
  `pyproject.toml` from Phase 1. **No torch at runtime.** The text encoder /
  `transformers` are used only by the offline embedding-generation script.
- **Dockerfile downloads the image encoder at build time** from a pinned URL with
  **SHA-256 + byte-size verification**, into `app/weights/` — extend
  `scripts/fetch_weights.sh` (which already does exactly this for YOLOX). Prefer
  an int8-quantised export (~90 MB) to stay light on the shared NAS; exact
  URL/size/SHA-256 pinned during implementation against the chosen HF export.
- **License hygiene:** ship only MIT/Apache-2.0 weights. OpenAI CLIP weights are
  MIT. If a FashionCLIP variant is added later, confirm its licence (patrickjohncyh
  FashionCLIP = MIT; Marqo-FashionCLIP = Apache-2.0) at that time.

## Deployment (`compose.prod.yaml`)

- Flip the `inference` service's `RECOGNIZER` from `bib` → **`full`**.
- Resource caps unchanged (`mem_limit: 2g` / `cpus: 2`): the int8 image encoder
  adds well under ~150 MB resident on top of Phase 1's models.
- Latency per photo = 1 person-detect + N torso-crop encodes + 1 whole-image
  encode + Phase 1 bib work. CPU-bound, single uvicorn worker; minutes/photo is
  acceptable and throughput is driven by PHP messenger worker replicas.
- Dev `compose.yaml` stays `stub` unless overridden. Deploy is the usual
  `git pull` → `compose build` → `up` on the NAS.

## Testing & eval

- **Unit (no weights):** the scoring/emission core in isolation — cosine→softmax,
  the top-k + margin + floor filter (accept/reject table across floor and margin
  edges), union + dedup keep-max-conf, and the registry wiring — driven by
  **synthetic embedding vectors** (no ONNX session). Deterministic and fast.
  `CompositeRecognizer` merge behaviour (bib real + clothing stub/real) unit-tested
  with fakes.
- **Integration (`@pytest.mark.models`, skipped without weights):** the full
  clothing/scene pipeline over a few committed **synthetic** crops (e.g. solid
  colour swatches, a simple garment render) → asserts expected labels. A
  build-time smoke test exercises the real path inside the image.
- **Eval harness (`inference/eval/`):** extend `run_eval.py` to report per-field
  **precision / recall** for colour/type/scene alongside bibs.
  - Commit `eval/labels_clothing.json` (photo id → photo-level union of visibly
    present colour/type/scene). Real photos remain **gitignored PII**; only
    labels + harness + a metrics summary are committed.
  - **Acceptance:** tune floors/margins **recall-lean**, target precision ≈ 0.70
    per field; recall reported and secondary. Colour tightened independently if
    its false-positive rate is unacceptable. Final numbers recorded in the metrics
    summary.
- **No-disk-writes test:** extend the Phase 1 assertion to cover the clothing/scene
  path (crops + embeddings stay in memory).
- **PHP side:** **no changes.** The contract still returns the same four lists;
  `FakeAttributeExtractorClient` and all downstream tests are unaffected.

## Boundary enforcement (explicit checklist)

- Person detection is **region-gating only** — reused verbatim from Phase 1; no
  face detection, landmarks, identity embeddings, or demographic inference
  anywhere in the path.
- Only colour/type/scene (fixed non-identifying vocab) are emitted; `main.py`
  vocab-filters them.
- **In-memory only** — asserted by test; no temp files for crops or embeddings.

## Non-goals

Faces, any biometric identifier, demographic inference, background text/signage,
license plates, identity inference. Per-garment localisation/segmentation.
Building FashionCLIP or any second model in v1 (the architecture *accommodates*
it; wiring it is a later phase). A pixel-based colour path (documented fallback,
not built). Any PHP-side change.

## Open items to finalise during implementation

- Pin the exact CLIP image-encoder ONNX export (URL, SHA-256, byte size); confirm
  int8 vs fp32 size/accuracy trade on the eval corpus.
- Finalise prompt-ensemble templates per field against the eval corpus.
- Calibrate per-field `FLOOR` / `MARGIN` / `k` and the softmax temperature toward
  the recall-lean target; record the metrics summary.
- Confirm the colour false-positive rate is acceptable under CLIP; if not, tighten
  colour's floor or note the pixel-fallback as a follow-up.

## References

- Phase 1 spec: `docs/superpowers/specs/2026-07-17-117-real-bib-recognizer-design.md`
- Phase 1 plan: `docs/superpowers/plans/2026-07-17-117-real-bib-recognizer.md`
- Pipeline: `docs/superpowers/plans/2026-07-15-109b-inference-service.md`,
  `-109c-extraction-pipeline.md`
- Boundary/policy: `docs/superpowers/specs/2026-07-15-109-indexable-attribute-boundary-design.md`
- Plug point + composite: `inference/app/recognizer.py`,
  `inference/app/bib_recognizer.py` (`Phase1Recognizer` → `CompositeRecognizer`)
- Handler that calls `/extract` (no clothing/scene gate):
  `src/MessageHandler/ExtractPhotoAttributesHandler.php`
