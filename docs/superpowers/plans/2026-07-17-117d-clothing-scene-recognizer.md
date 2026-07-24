# Clothing/Scene Recognizer (Phase 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the stubbed clothing-colour / clothing-type / scene half of the inference recognizer with a real, CPU-only, zero-shot CLIP classifier that attributes clothing per detected person and scene per photo.

**Architecture:** A CLIP-family image encoder (ONNX, CPU) produces an embedding for each Phase-1 torso crop and for the whole image; per-field `ZeroShotClassifier`s score those embeddings against **precomputed, committed text embeddings** and emit labels via a top-k + margin + floor filter. A classifier registry references named encoders so a second model can be added later without touching the pipeline. The existing `Phase1Recognizer` is refactored into a general `CompositeRecognizer` so `RECOGNIZER=stub|bib|full` compose cleanly.

**Tech Stack:** Python 3.12, FastAPI, onnxruntime (CPU), NumPy, Pillow — all already in `inference/pyproject.toml`. No torch at runtime. The offline embedding generator uses `transformers`/`torch` in a throwaway venv only.

## Global Constraints

- **CPU-only, self-hosted, in-memory only** — the image, all crops, and all embeddings stay in PIL/NumPy memory; **no disk writes** in the request path (enforced by test).
- **No new runtime dependencies** — `onnxruntime`, `numpy`, `pillow` are already present; **do not add torch/transformers to `pyproject.toml`**. The generator script's imports are local and never imported by `app/`.
- **HTTP contract unchanged** — `POST /extract` still returns `{clothing_colors, clothing_types, scenes, bibs}` of `{value, confidence}`; `main.py` vocab-filters colour/type/scene. **No PHP-side changes.**
- **Fixed vocabulary** — only labels in `inference/app/vocabulary.py` (`clothing_colors` 12, `clothing_types` 9, `scenes` 6). Adding a term is a spec change.
- **Boundary (#109):** person detection is region-gating only — reuse Phase 1's `PersonDetector`/`torso_crop` verbatim; no faces, landmarks, identity embeddings, or demographics.
- **Fast suite runs without weights** — default `RECOGNIZER=stub`; every test needing the ONNX encoder or real embedding JSON is gated behind `@pytest.mark.models` and skips when weights are absent.
- **Ship only MIT/Apache-2.0 weights** — default model is OpenAI CLIP ViT-B/32 (MIT).
- **Emission posture:** recall-lean (target precision ≈ 0.70), top-k + margin + per-field floor.
- All commands run from `inference/` unless stated. Commit messages must contain the issue number `117`.

## File Structure

- Create `inference/app/clip_config.py` — tunable constants: CLIP preprocessing (input size, mean, std), encoder weights path, text-embeddings dir, per-field `FLOOR`/`MARGIN`/`TOPK`, softmax `TEMPERATURE`, and `PROMPT_TEMPLATES`.
- Create `inference/app/zero_shot.py` — `expand_prompts()`, `softmax()`, `ZeroShotClassifier`, `load_classifier()`.
- Create `inference/app/clip_encoder.py` — `ClipImageEncoder` (ONNX image encoder + preprocessing).
- Create `inference/app/clothing_scene_recognizer.py` — `ClothingSceneRecognizer` + `build_clothing_scene_recognizer()`.
- Create `inference/app/text_embeddings/clothing_colors.json`, `clothing_types.json`, `scenes.json` — committed generated artifacts.
- Create `inference/scripts/build_text_embeddings.py` — offline generator (transformers venv).
- Modify `inference/app/bib_recognizer.py` — `Phase1Recognizer` → `CompositeRecognizer`; add `build_full_recognizer()`.
- Modify `inference/app/main.py:14-25` — `build_recognizer` gains `full`.
- Modify `inference/scripts/fetch_weights.sh` — download the CLIP image encoder ONNX.
- Modify `inference/eval/run_eval.py` — per-field clothing/scene precision/recall.
- Create `inference/eval/labels_clothing.example.json`.
- Modify `compose.prod.yaml:66` — `RECOGNIZER: "full"`.
- Tests: create `tests/test_zero_shot.py`, `tests/test_clothing_scene_recognizer.py`, `tests/test_clip_encoder.py`, `tests/fixtures/toy_embeddings.json`; modify `tests/test_recognizer_selection.py`, `tests/test_no_disk_writes.py`.

---

### Task 1: Config + zero-shot scoring core

The pure, weight-free precision core: cosine → softmax → top-k + margin + floor. No ONNX, no JSON I/O here.

**Files:**
- Create: `inference/app/clip_config.py`
- Create: `inference/app/zero_shot.py`
- Test: `inference/tests/test_zero_shot.py`

**Interfaces:**
- Consumes: `app.recognizer.Score`, `app.vocabulary.VOCABULARY`.
- Produces:
  - `clip_config.FLOOR: dict[str,float]`, `MARGIN: dict[str,float]`, `TOPK: dict[str,int]`, `TEMPERATURE: float`, `PROMPT_TEMPLATES: dict[str,list[str]]`.
  - `zero_shot.softmax(x: np.ndarray) -> np.ndarray`
  - `zero_shot.expand_prompts(field: str, label: str) -> list[str]`
  - `zero_shot.ZeroShotClassifier(field: str, labels: list[str], text_matrix: np.ndarray)` with `.classify(image_embedding: np.ndarray) -> list[Score]`

- [ ] **Step 1: Write the failing test**

```python
# inference/tests/test_zero_shot.py
import numpy as np
import pytest

from app.zero_shot import ZeroShotClassifier, softmax, expand_prompts


def _unit(vec):
    v = np.asarray(vec, dtype=np.float32)
    return v / np.linalg.norm(v)


# A toy 3-dim embedding space: three orthogonal "labels".
LABELS = ["red", "blue", "green"]
MATRIX = np.stack([_unit([1, 0, 0]), _unit([0, 1, 0]), _unit([0, 0, 1])])


def _clf(field="clothing_colors"):
    return ZeroShotClassifier(field, LABELS, MATRIX)


def test_softmax_sums_to_one():
    out = softmax(np.array([1.0, 2.0, 3.0]))
    assert out.shape == (3,)
    assert abs(float(out.sum()) - 1.0) < 1e-6
    assert out[2] > out[1] > out[0]


def test_top_label_wins_and_is_scored():
    scores = _clf().classify(_unit([0.95, 0.1, 0.1]))
    assert scores, "expected at least the top label"
    assert scores[0].value == "red"
    assert 0.0 < scores[0].confidence <= 1.0


def test_labels_below_floor_are_dropped(monkeypatch):
    # Force a high floor: a near-uniform embedding should clear nothing.
    monkeypatch.setitem(__import__("app.clip_config", fromlist=["FLOOR"]).FLOOR,
                        "clothing_colors", 0.99)
    scores = _clf().classify(_unit([1, 1, 1]))
    assert scores == []


def test_margin_admits_a_close_second():
    import app.clip_config as cfg
    # Wide margin + low floor -> a genuinely close second label is admitted.
    cfg.FLOOR["clothing_colors"] = 0.0
    cfg.MARGIN["clothing_colors"] = 1.0
    cfg.TOPK["clothing_colors"] = 3
    scores = _clf().classify(_unit([1, 0.98, 0.0]))
    values = {s.value for s in scores}
    assert {"red", "blue"} <= values


def test_topk_caps_emitted_labels():
    import app.clip_config as cfg
    cfg.FLOOR["clothing_colors"] = 0.0
    cfg.MARGIN["clothing_colors"] = 1.0
    cfg.TOPK["clothing_colors"] = 1
    scores = _clf().classify(_unit([1, 0.9, 0.8]))
    assert len(scores) == 1
    assert scores[0].value == "red"


def test_expand_prompts_uses_templates_and_label():
    prompts = expand_prompts("clothing_colors", "red")
    assert prompts, "expected at least one prompt"
    assert all("red" in p for p in prompts)
```

- [ ] **Step 2: Run test to verify it fails**

Run: `SKIP_SCHEMA_REBUILD=1 python -m pytest tests/test_zero_shot.py -v`
Expected: FAIL — `ModuleNotFoundError: No module named 'app.zero_shot'`

- [ ] **Step 3: Write `clip_config.py`**

```python
# inference/app/clip_config.py
"""Tunable constants for the clothing/scene zero-shot recognizer (#117 Phase 2).
Floors/margins/temperature are calibrated against inference/eval/ toward a
recall-lean posture (target precision ~0.70). Per-field so colour can be
tightened independently if CLIP colour-naming proves noisy. See the design spec
docs/superpowers/specs/2026-07-17-117d-clothing-scene-recognizer-design.md."""

from pathlib import Path

# --- CLIP image preprocessing (ViT-B/32) ---
CLIP_INPUT_SIZE: int = 224
CLIP_MEAN: tuple[float, float, float] = (0.48145466, 0.4578275, 0.40821073)
CLIP_STD: tuple[float, float, float] = (0.26862954, 0.26130258, 0.27577711)
CLIP_WEIGHTS_PATH: Path = Path(__file__).parent / "weights" / "clip_vitb32_vision.onnx"
CLIP_MODEL_ID: str = "openai/clip-vit-base-patch32"  # embedding space the text JSON must match

TEXT_EMBEDDINGS_DIR: Path = Path(__file__).parent / "text_embeddings"

# --- Emission policy (recall-lean; per field) ---
# softmax temperature over cosine similarities; lower = sharper. CLIP's native
# logit scale is ~0.01; we soften to admit close seconds for a recall posture.
TEMPERATURE: float = 0.05

# Keep a label when prob >= FLOOR and (top_prob - prob) <= MARGIN, capped at TOPK.
FLOOR: dict[str, float] = {
    "clothing_colors": 0.15,
    "clothing_types": 0.15,
    "scenes": 0.20,
}
MARGIN: dict[str, float] = {
    "clothing_colors": 0.25,
    "clothing_types": 0.25,
    "scenes": 0.20,
}
TOPK: dict[str, int] = {
    "clothing_colors": 2,
    "clothing_types": 2,
    "scenes": 2,
}

# Prompt ensemble per field; averaged + L2-normalized at generation time.
PROMPT_TEMPLATES: dict[str, list[str]] = {
    "clothing_colors": [
        "a photo of a person wearing {label} clothing",
        "a runner wearing {label}",
        "{label} clothing",
    ],
    "clothing_types": [
        "a photo of a person wearing a {label}",
        "a runner wearing a {label}",
        "a {label}",
    ],
    "scenes": [
        "a photo of the {label} at a running race",
        "a running event {label} scene",
        "{label}",
    ],
}
```

- [ ] **Step 4: Write `zero_shot.py` (scoring core only)**

```python
# inference/app/zero_shot.py
import numpy as np

from app import clip_config
from app.recognizer import Score


def softmax(x: np.ndarray) -> np.ndarray:
    z = x - np.max(x)
    e = np.exp(z)
    return e / e.sum()


def expand_prompts(field: str, label: str) -> list[str]:
    return [t.format(label=label) for t in clip_config.PROMPT_TEMPLATES[field]]


class ZeroShotClassifier:
    """Scores an L2-normalized image embedding against a field's precomputed
    text-embedding matrix, emitting in-vocab labels via top-k + margin + floor."""

    def __init__(self, field: str, labels: list[str], text_matrix: np.ndarray):
        self._field = field
        self._labels = labels
        self._matrix = np.asarray(text_matrix, dtype=np.float32)

    def classify(self, image_embedding: np.ndarray) -> list[Score]:
        emb = np.asarray(image_embedding, dtype=np.float32)
        sims = self._matrix @ emb                      # cosine (both normalized)
        probs = softmax(sims / clip_config.TEMPERATURE)
        top = float(probs.max())
        floor = clip_config.FLOOR[self._field]
        margin = clip_config.MARGIN[self._field]
        topk = clip_config.TOPK[self._field]

        order = np.argsort(probs)[::-1]
        kept: list[Score] = []
        for i in order:
            p = float(probs[i])
            if p < floor or (top - p) > margin:
                continue
            kept.append(Score(self._labels[i], p))
            if len(kept) >= topk:
                break
        return kept
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `SKIP_SCHEMA_REBUILD=1 python -m pytest tests/test_zero_shot.py -v`
Expected: PASS (6 passed)

- [ ] **Step 6: Commit**

```bash
git add inference/app/clip_config.py inference/app/zero_shot.py inference/tests/test_zero_shot.py
git commit -m "117 - Phase 2: zero-shot scoring core (cosine->softmax->topk/margin/floor) + clip_config"
```

---

### Task 2: Text-embedding JSON loader

Loads a committed per-field JSON into a `ZeroShotClassifier`, validating the embedding space and vocabulary.

**Files:**
- Modify: `inference/app/zero_shot.py`
- Create: `inference/tests/fixtures/toy_embeddings.json`
- Test: `inference/tests/test_zero_shot.py` (extend)

**Interfaces:**
- Produces: `zero_shot.load_classifier(field: str, path: Path, expected_dim: int | None = None) -> ZeroShotClassifier`
- JSON schema: `{"model": str, "dim": int, "labels": [str, ...], "embeddings": [[float, ...], ...]}` — each embedding row length == `dim`, `len(embeddings) == len(labels)`, `labels ⊆ VOCABULARY[field]`.

- [ ] **Step 1: Write the fixture**

```json
// inference/tests/fixtures/toy_embeddings.json
{
  "model": "toy",
  "dim": 3,
  "labels": ["red", "blue", "green"],
  "embeddings": [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]]
}
```

- [ ] **Step 2: Write the failing test (append to `tests/test_zero_shot.py`)**

```python
from pathlib import Path

from app.zero_shot import load_classifier

_FIXTURE = Path(__file__).parent / "fixtures" / "toy_embeddings.json"


def test_load_classifier_reads_labels_and_matrix():
    clf = load_classifier("clothing_colors", _FIXTURE)
    scores = clf.classify(_unit([0.95, 0.1, 0.1]))
    assert scores[0].value == "red"


def test_load_classifier_rejects_dim_mismatch():
    with pytest.raises(ValueError, match="dim"):
        load_classifier("clothing_colors", _FIXTURE, expected_dim=512)


def test_load_classifier_rejects_out_of_vocab_label(tmp_path):
    bad = tmp_path / "bad.json"
    bad.write_text('{"model":"toy","dim":1,"labels":["not-a-colour"],'
                   '"embeddings":[[1.0]]}')
    with pytest.raises(ValueError, match="vocab"):
        load_classifier("clothing_colors", bad)
```

- [ ] **Step 3: Run test to verify it fails**

Run: `SKIP_SCHEMA_REBUILD=1 python -m pytest tests/test_zero_shot.py -k load_classifier -v`
Expected: FAIL — `ImportError: cannot import name 'load_classifier'`

- [ ] **Step 4: Implement `load_classifier` (append to `zero_shot.py`)**

```python
import json
from pathlib import Path

from app.vocabulary import VOCABULARY


def load_classifier(field: str, path: Path, expected_dim: int | None = None) -> ZeroShotClassifier:
    data = json.loads(Path(path).read_text())
    labels = list(data["labels"])
    dim = int(data["dim"])
    matrix = np.asarray(data["embeddings"], dtype=np.float32)

    if matrix.shape != (len(labels), dim):
        raise ValueError(
            f"{path}: embeddings shape {matrix.shape} != (labels={len(labels)}, dim={dim})"
        )
    if expected_dim is not None and dim != expected_dim:
        raise ValueError(f"{path}: dim {dim} != expected image-encoder dim {expected_dim}")
    unknown = set(labels) - VOCABULARY[field]
    if unknown:
        raise ValueError(f"{path}: labels not in {field} vocab: {sorted(unknown)}")
    return ZeroShotClassifier(field, labels, matrix)
```

Add `from pathlib import Path` / `import json` to the existing imports (dedupe).

- [ ] **Step 5: Run tests to verify they pass**

Run: `SKIP_SCHEMA_REBUILD=1 python -m pytest tests/test_zero_shot.py -v`
Expected: PASS (9 passed)

- [ ] **Step 6: Commit**

```bash
git add inference/app/zero_shot.py inference/tests/test_zero_shot.py inference/tests/fixtures/toy_embeddings.json
git commit -m "117 - Phase 2: text-embedding JSON loader with dim + vocab validation"
```

---

### Task 3: Offline text-embedding generator + committed artifacts

A standalone script (transformers venv) that embeds the prompt ensemble per label and writes the committed JSON. Not imported by `app/`, never in the runtime image.

**Files:**
- Create: `inference/scripts/build_text_embeddings.py`
- Create: `inference/app/text_embeddings/clothing_colors.json`, `clothing_types.json`, `scenes.json` (generated output, committed)

**Interfaces:**
- Consumes: `clip_config.PROMPT_TEMPLATES`, `clip_config.CLIP_MODEL_ID`, `zero_shot.expand_prompts`, `vocabulary.VOCABULARY`.
- Produces: three JSON files matching the Task-2 schema, `dim == 512`, `model == CLIP_MODEL_ID`.

- [ ] **Step 1: Write the generator script**

```python
# inference/scripts/build_text_embeddings.py
"""Offline generator for the committed text-embedding JSON (#117 Phase 2).

Run deliberately in a throwaway venv — NOT part of CI or the Docker build:

    python -m venv /tmp/clipgen && . /tmp/clipgen/bin/activate
    pip install torch transformers
    cd inference && python scripts/build_text_embeddings.py

The output MUST be regenerated if CLIP_MODEL_ID or PROMPT_TEMPLATES change; its
embedding space must match the shipped ONNX image encoder (same checkpoint)."""

import json
from pathlib import Path

import numpy as np
import torch
from transformers import CLIPModel, CLIPTokenizerFast

from app import clip_config
from app.vocabulary import VOCABULARY
from app.zero_shot import expand_prompts


def main() -> None:
    model = CLIPModel.from_pretrained(clip_config.CLIP_MODEL_ID).eval()
    tokenizer = CLIPTokenizerFast.from_pretrained(clip_config.CLIP_MODEL_ID)
    out_dir = clip_config.TEXT_EMBEDDINGS_DIR
    out_dir.mkdir(parents=True, exist_ok=True)

    for field, labels in VOCABULARY.items():
        rows = []
        ordered = sorted(labels)
        for label in ordered:
            prompts = expand_prompts(field, label)
            tokens = tokenizer(prompts, padding=True, return_tensors="pt")
            with torch.no_grad():
                feats = model.get_text_features(**tokens)
            feats = feats / feats.norm(dim=-1, keepdim=True)
            mean = feats.mean(dim=0)
            mean = mean / mean.norm()
            rows.append(mean.cpu().numpy().astype(np.float32))
        matrix = np.stack(rows)
        payload = {
            "model": clip_config.CLIP_MODEL_ID,
            "dim": int(matrix.shape[1]),
            "labels": ordered,
            "embeddings": [[round(float(x), 6) for x in row] for row in matrix],
        }
        path = out_dir / f"{field}.json"
        path.write_text(json.dumps(payload, indent=2))
        print(f"wrote {path} ({matrix.shape[0]} labels x {matrix.shape[1]} dim)")


if __name__ == "__main__":
    main()
```

- [ ] **Step 2: Generate the artifacts**

Run (in a transformers venv, from `inference/`):
```bash
python -m venv /tmp/clipgen && . /tmp/clipgen/bin/activate
pip install torch transformers
python scripts/build_text_embeddings.py
deactivate
```
Expected: prints three `wrote app/text_embeddings/<field>.json` lines; `dim` == 512.

- [ ] **Step 3: Verify the artifacts load and validate**

Run: `SKIP_SCHEMA_REBUILD=1 python -c "from pathlib import Path; from app.zero_shot import load_classifier; [load_classifier(f, Path('app/text_embeddings')/f'{f}.json', 512) for f in ('clothing_colors','clothing_types','scenes')]; print('ok')"`
Expected: `ok` (no ValueError — dims are 512, labels in-vocab)

- [ ] **Step 4: Commit**

```bash
git add inference/scripts/build_text_embeddings.py inference/app/text_embeddings/
git commit -m "117 - Phase 2: offline text-embedding generator + committed ViT-B/32 embeddings"
```

---

### Task 4: CLIP image encoder + weight fetch

Wraps the ONNX vision tower with CLIP preprocessing. Weight-gated (`@models`).

**Files:**
- Create: `inference/app/clip_encoder.py`
- Modify: `inference/scripts/fetch_weights.sh`
- Test: `inference/tests/test_clip_encoder.py`

**Interfaces:**
- Consumes: `clip_config.CLIP_INPUT_SIZE`, `CLIP_MEAN`, `CLIP_STD`, `CLIP_WEIGHTS_PATH`.
- Produces: `ClipImageEncoder(weights_path=CLIP_WEIGHTS_PATH)` with `.encode(image: Image.Image) -> np.ndarray` returning an L2-normalized `float32` vector of shape `(512,)`.

- [ ] **Step 1: Extend `fetch_weights.sh`**

Append below the YOLOX block (pin the exact export at implementation time — an ONNX vision tower of `openai/clip-vit-base-patch32`, e.g. the `Qdrant/clip-ViT-B-32-vision` export; fill real values):

```sh
# Bake the CLIP ViT-B/32 vision encoder ONNX (MIT) into the image.
CLIP_DEST="$(dirname "$0")/../app/weights/clip_vitb32_vision.onnx"
CLIP_URL="<PIN_EXACT_URL>"
CLIP_EXPECTED_BYTES="<PIN_BYTES>"
CLIP_EXPECTED_SHA256="<PIN_SHA256>"

if [ -f "$CLIP_DEST" ] && [ "$(wc -c < "$CLIP_DEST" | tr -d '[:space:]')" = "$CLIP_EXPECTED_BYTES" ]; then
    echo "clip_vitb32_vision.onnx already present and correct size."
else
    echo "Downloading clip_vitb32_vision.onnx ..."
    curl -fSL "$CLIP_URL" -o "$CLIP_DEST"
fi
CLIP_ACTUAL_BYTES="$(wc -c < "$CLIP_DEST" | tr -d '[:space:]')"
if [ "$CLIP_ACTUAL_BYTES" != "$CLIP_EXPECTED_BYTES" ]; then
    echo "CLIP SIZE MISMATCH: expected $CLIP_EXPECTED_BYTES got $CLIP_ACTUAL_BYTES" >&2
    exit 1
fi
if command -v sha256sum >/dev/null 2>&1; then
    CLIP_ACTUAL_SHA256="$(sha256sum "$CLIP_DEST" | awk '{print $1}')"
else
    CLIP_ACTUAL_SHA256="$(shasum -a 256 "$CLIP_DEST" | awk '{print $1}')"
fi
if [ "$CLIP_ACTUAL_SHA256" != "$CLIP_EXPECTED_SHA256" ]; then
    echo "CLIP SHA256 MISMATCH: expected $CLIP_EXPECTED_SHA256 got $CLIP_ACTUAL_SHA256" >&2
    exit 1
fi
echo "clip sha256 OK: $CLIP_ACTUAL_SHA256"
```

Then fetch it locally for the test: `sh scripts/fetch_weights.sh`
Expected: `clip sha256 OK: ...`

- [ ] **Step 2: Write the failing test**

```python
# inference/tests/test_clip_encoder.py
import numpy as np
import pytest
from PIL import Image

from app.clip_config import CLIP_WEIGHTS_PATH


pytestmark = pytest.mark.models


@pytest.fixture()
def encoder():
    if not CLIP_WEIGHTS_PATH.exists():
        pytest.skip("clip weights absent")
    from app.clip_encoder import ClipImageEncoder
    return ClipImageEncoder()


def test_encode_returns_normalized_512(encoder):
    img = Image.new("RGB", (300, 200), (200, 30, 30))
    emb = encoder.encode(img)
    assert emb.shape == (512,)
    assert emb.dtype == np.float32
    assert abs(float(np.linalg.norm(emb)) - 1.0) < 1e-4


def test_encode_discriminates_colours(encoder):
    from app.zero_shot import load_classifier
    from app.clip_config import TEXT_EMBEDDINGS_DIR
    clf = load_classifier("clothing_colors", TEXT_EMBEDDINGS_DIR / "clothing_colors.json", 512)
    red = clf.classify(encoder.encode(Image.new("RGB", (224, 224), (220, 20, 20))))
    blue = clf.classify(encoder.encode(Image.new("RGB", (224, 224), (30, 60, 220))))
    assert red and red[0].value == "red"
    assert blue and blue[0].value == "blue"
```

- [ ] **Step 3: Run test to verify it fails**

Run: `SKIP_SCHEMA_REBUILD=1 python -m pytest tests/test_clip_encoder.py -v`
Expected: FAIL — `ModuleNotFoundError: No module named 'app.clip_encoder'` (or SKIP if weights truly absent; ensure Step 1 fetched them so it FAILS meaningfully)

- [ ] **Step 4: Implement `ClipImageEncoder`**

```python
# inference/app/clip_encoder.py
import numpy as np
import onnxruntime as ort
from PIL import Image

from app.clip_config import CLIP_INPUT_SIZE, CLIP_MEAN, CLIP_STD, CLIP_WEIGHTS_PATH


class ClipImageEncoder:
    """CLIP ViT-B/32 vision tower (ONNX, CPU). encode() is in-memory only."""

    def __init__(self, weights_path=CLIP_WEIGHTS_PATH):
        self._session = ort.InferenceSession(
            str(weights_path), providers=["CPUExecutionProvider"]
        )
        self._input_name = self._session.get_inputs()[0].name
        self._mean = np.asarray(CLIP_MEAN, dtype=np.float32).reshape(3, 1, 1)
        self._std = np.asarray(CLIP_STD, dtype=np.float32).reshape(3, 1, 1)

    def _preprocess(self, image: Image.Image) -> np.ndarray:
        rgb = image.convert("RGB")
        w, h = rgb.size
        scale = CLIP_INPUT_SIZE / min(w, h)
        rgb = rgb.resize((round(w * scale), round(h * scale)), Image.BICUBIC)
        w, h = rgb.size
        left = (w - CLIP_INPUT_SIZE) // 2
        top = (h - CLIP_INPUT_SIZE) // 2
        rgb = rgb.crop((left, top, left + CLIP_INPUT_SIZE, top + CLIP_INPUT_SIZE))
        arr = np.asarray(rgb, dtype=np.float32) / 255.0        # HWC [0,1]
        chw = arr.transpose(2, 0, 1)
        chw = (chw - self._mean) / self._std
        return chw[np.newaxis, ...].astype(np.float32)

    def encode(self, image: Image.Image) -> np.ndarray:
        blob = self._preprocess(image)
        out = self._session.run(None, {self._input_name: blob})[0]
        vec = np.asarray(out, dtype=np.float32).reshape(-1)
        return vec / np.linalg.norm(vec)
```

If the pinned ONNX export expects a different input name/dtype, adapt `_input_name`/blob accordingly — the test asserts the observable contract (shape 512, unit norm, colour discrimination).

- [ ] **Step 5: Run tests to verify they pass**

Run: `SKIP_SCHEMA_REBUILD=1 python -m pytest tests/test_clip_encoder.py -v`
Expected: PASS (2 passed) — requires weights + generated JSON from Task 3

- [ ] **Step 6: Commit**

```bash
git add inference/app/clip_encoder.py inference/scripts/fetch_weights.sh inference/tests/test_clip_encoder.py
git commit -m "117 - Phase 2: CLIP ViT-B/32 ONNX image encoder + build-time weight fetch"
```

---

### Task 5: ClothingSceneRecognizer

Orchestrates per-person clothing (union/dedup) + whole-image scene. Unit-tested with fakes — no weights.

**Files:**
- Create: `inference/app/clothing_scene_recognizer.py`
- Test: `inference/tests/test_clothing_scene_recognizer.py`

**Interfaces:**
- Consumes: `app.detect.Box`/`PersonDetector`, `app.crop.torso_crop`, `app.clip_encoder.ClipImageEncoder`, `app.zero_shot.ZeroShotClassifier`/`load_classifier`, `app.recognizer.RawResult`/`Score`.
- Produces:
  - `ClothingSceneRecognizer(detector, encoder, color_clf, type_clf, scene_clf)` with `.recognize(image) -> RawResult` (bibs always empty).
  - `build_clothing_scene_recognizer() -> ClothingSceneRecognizer` (real `PersonDetector` + `ClipImageEncoder` + three `load_classifier` calls against `TEXT_EMBEDDINGS_DIR`).

- [ ] **Step 1: Write the failing test**

```python
# inference/tests/test_clothing_scene_recognizer.py
import numpy as np

from app.clothing_scene_recognizer import ClothingSceneRecognizer
from app.detect import Box
from app.zero_shot import ZeroShotClassifier


def _unit(vec):
    v = np.asarray(vec, dtype=np.float32)
    return v / np.linalg.norm(v)


COLOR_LABELS = ["red", "blue"]
COLOR_MATRIX = np.stack([_unit([1, 0]), _unit([0, 1])])


class FakeDetector:
    def __init__(self, boxes):
        self._boxes = boxes

    def detect(self, image):
        return self._boxes


class FakeEncoder:
    """Returns preset vectors in call order: N crop encodes, then whole-image."""

    def __init__(self, vectors):
        self._vectors = list(vectors)
        self._i = 0

    def encode(self, image):
        vec = self._vectors[self._i]
        self._i += 1
        return vec


def _clf(field, labels, matrix):
    return ZeroShotClassifier(field, labels, matrix)


def _rec(detector, encoder):
    import app.clip_config as cfg
    cfg.FLOOR["clothing_colors"] = 0.0
    cfg.MARGIN["clothing_colors"] = 0.0   # top-1 only, deterministic
    cfg.TOPK["clothing_colors"] = 1
    cfg.FLOOR["scenes"] = 0.0
    cfg.MARGIN["scenes"] = 0.0
    cfg.TOPK["scenes"] = 1
    scene_labels = ["finish-line", "start"]
    scene_matrix = np.stack([_unit([1, 0]), _unit([0, 1])])
    type_clf = _clf("clothing_types", ["t-shirt"], np.stack([_unit([1.0])]))
    return ClothingSceneRecognizer(
        detector, encoder,
        _clf("clothing_colors", COLOR_LABELS, COLOR_MATRIX),
        type_clf,
        _clf("scenes", scene_labels, scene_matrix),
    )


def _img():
    from PIL import Image
    return Image.new("RGB", (100, 100), (0, 0, 0))


def test_unions_colours_across_two_people():
    boxes = [Box(0, 0, 50, 100, 0.9), Box(50, 0, 100, 100, 0.9)]
    # person1 -> red, person2 -> blue, whole-image -> finish-line
    enc = FakeEncoder([_unit([1, 0]), _unit([0, 1]), _unit([1, 0])])
    result = _rec(FakeDetector(boxes), enc).recognize(_img())
    assert {s.value for s in result.clothing_colors} == {"red", "blue"}
    assert {s.value for s in result.scenes} == {"finish-line"}
    assert result.bibs == []


def test_dedups_same_colour_keeping_max_conf():
    boxes = [Box(0, 0, 50, 100, 0.9), Box(50, 0, 100, 100, 0.9)]
    # both people -> red (different strength), whole-image -> start
    enc = FakeEncoder([_unit([1, 0.2]), _unit([1, 0.0]), _unit([0, 1])])
    result = _rec(FakeDetector(boxes), enc).recognize(_img())
    reds = [s for s in result.clothing_colors if s.value == "red"]
    assert len(reds) == 1
    assert {s.value for s in result.scenes} == {"start"}


def test_no_persons_still_yields_scene():
    enc = FakeEncoder([_unit([1, 0])])   # only the whole-image encode happens
    result = _rec(FakeDetector([]), enc).recognize(_img())
    assert result.clothing_colors == []
    assert {s.value for s in result.scenes} == {"finish-line"}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `SKIP_SCHEMA_REBUILD=1 python -m pytest tests/test_clothing_scene_recognizer.py -v`
Expected: FAIL — `ModuleNotFoundError: No module named 'app.clothing_scene_recognizer'`

- [ ] **Step 3: Implement `ClothingSceneRecognizer`**

```python
# inference/app/clothing_scene_recognizer.py
from PIL import Image

from app.crop import torso_crop
from app.recognizer import RawResult, Score


def _keep_max(best: dict, score: Score) -> None:
    if score.value not in best or score.confidence > best[score.value]:
        best[score.value] = score.confidence


class ClothingSceneRecognizer:
    """Per-person clothing (union+dedup) + whole-image scene. In-memory only.
    Person detection is region-gating only (reused from Phase 1)."""

    def __init__(self, detector, encoder, color_clf, type_clf, scene_clf):
        self._detector = detector
        self._encoder = encoder
        self._color_clf = color_clf
        self._type_clf = type_clf
        self._scene_clf = scene_clf

    def recognize(self, image: Image.Image) -> RawResult:
        colors: dict[str, float] = {}
        types: dict[str, float] = {}
        for box in self._detector.detect(image):
            crop = torso_crop(image, box)
            emb = self._encoder.encode(crop)          # one encode, reused below
            for s in self._color_clf.classify(emb):
                _keep_max(colors, s)
            for s in self._type_clf.classify(emb):
                _keep_max(types, s)
        scene_emb = self._encoder.encode(image)
        scenes = list(self._scene_clf.classify(scene_emb))
        return RawResult(
            clothing_colors=[Score(v, c) for v, c in colors.items()],
            clothing_types=[Score(v, c) for v, c in types.items()],
            scenes=scenes,
            bibs=[],
        )


def build_clothing_scene_recognizer() -> ClothingSceneRecognizer:
    from app.clip_config import CLIP_WEIGHTS_PATH, TEXT_EMBEDDINGS_DIR
    from app.clip_encoder import ClipImageEncoder
    from app.detect import PersonDetector
    from app.zero_shot import load_classifier

    encoder = ClipImageEncoder(CLIP_WEIGHTS_PATH)
    return ClothingSceneRecognizer(
        PersonDetector(),
        encoder,
        load_classifier("clothing_colors", TEXT_EMBEDDINGS_DIR / "clothing_colors.json", 512),
        load_classifier("clothing_types", TEXT_EMBEDDINGS_DIR / "clothing_types.json", 512),
        load_classifier("scenes", TEXT_EMBEDDINGS_DIR / "scenes.json", 512),
    )
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `SKIP_SCHEMA_REBUILD=1 python -m pytest tests/test_clothing_scene_recognizer.py -v`
Expected: PASS (3 passed)

- [ ] **Step 5: Commit**

```bash
git add inference/app/clothing_scene_recognizer.py inference/tests/test_clothing_scene_recognizer.py
git commit -m "117 - Phase 2: ClothingSceneRecognizer (per-person union/dedup + whole-image scene)"
```

---

### Task 6: CompositeRecognizer refactor + `full` selection

Refactor `Phase1Recognizer` → general `CompositeRecognizer`; add `full` to `build_recognizer`. Update selection + no-disk tests.

**Files:**
- Modify: `inference/app/bib_recognizer.py`
- Modify: `inference/app/main.py:14-25`
- Modify: `inference/tests/test_recognizer_selection.py`
- Modify: `inference/tests/test_no_disk_writes.py`

**Interfaces:**
- Consumes: `BibRecognizer.recognize_bibs`, `StubRecognizer.recognize`, `build_clothing_scene_recognizer`.
- Produces:
  - `bib_recognizer.CompositeRecognizer(bib_component, clothing_scene_component)` with `.recognize(image) -> RawResult`. `bib_component` exposes `recognize_bibs(image) -> list[Score]`; `clothing_scene_component` exposes `recognize(image) -> RawResult` (colour/type/scene used, bibs ignored).
  - `bib_recognizer.build_bib_recognizer() -> CompositeRecognizer` (real bibs + `StubRecognizer`).
  - `bib_recognizer.build_full_recognizer() -> CompositeRecognizer` (real bibs + `ClothingSceneRecognizer`).

- [ ] **Step 1: Update the selection test**

Replace `tests/test_recognizer_selection.py` with:

```python
import pytest

from app.main import build_recognizer
from app.recognizer import StubRecognizer


def test_default_and_stub_select_stub():
    assert isinstance(build_recognizer("stub"), StubRecognizer)


def test_unknown_value_raises():
    with pytest.raises(ValueError):
        build_recognizer("nope")


@pytest.mark.models
def test_bib_selection_builds_composite():
    from app.bib_config import YOLOX_WEIGHTS_PATH
    if not YOLOX_WEIGHTS_PATH.exists():
        pytest.skip("yolox weights absent")
    from app.bib_recognizer import CompositeRecognizer
    assert isinstance(build_recognizer("bib"), CompositeRecognizer)


@pytest.mark.models
def test_full_selection_builds_composite():
    from app.bib_config import YOLOX_WEIGHTS_PATH
    from app.clip_config import CLIP_WEIGHTS_PATH
    if not (YOLOX_WEIGHTS_PATH.exists() and CLIP_WEIGHTS_PATH.exists()):
        pytest.skip("weights absent")
    from app.bib_recognizer import CompositeRecognizer
    assert isinstance(build_recognizer("full"), CompositeRecognizer)
```

- [ ] **Step 2: Run test to verify it fails**

Run: `SKIP_SCHEMA_REBUILD=1 python -m pytest tests/test_recognizer_selection.py -v`
Expected: FAIL — `ImportError: cannot import name 'CompositeRecognizer'`

- [ ] **Step 3: Refactor `bib_recognizer.py`**

Replace the `Phase1Recognizer` class and `build_bib_recognizer` with:

```python
class CompositeRecognizer:
    """Real bibs + a pluggable clothing/scene component. `bib` uses the stub for
    the clothing half; `full` uses the real ClothingSceneRecognizer."""

    def __init__(self, bib_component, clothing_scene_component):
        self._bibs = bib_component
        self._clothing_scene = clothing_scene_component

    def recognize(self, image: Image.Image) -> RawResult:
        cs = self._clothing_scene.recognize(image)
        return RawResult(
            clothing_colors=cs.clothing_colors,
            clothing_types=cs.clothing_types,
            scenes=cs.scenes,
            bibs=self._bibs.recognize_bibs(image),
        )


def build_bib_recognizer() -> CompositeRecognizer:
    from app.detect import PersonDetector
    from app.ocr import TextReader

    return CompositeRecognizer(
        BibRecognizer(PersonDetector(), TextReader(), BibParser()),
        StubRecognizer(),
    )


def build_full_recognizer() -> CompositeRecognizer:
    from app.clothing_scene_recognizer import build_clothing_scene_recognizer
    from app.detect import PersonDetector
    from app.ocr import TextReader

    return CompositeRecognizer(
        BibRecognizer(PersonDetector(), TextReader(), BibParser()),
        build_clothing_scene_recognizer(),
    )
```

(`BibRecognizer`, its imports, and `StubRecognizer` stay as-is.)

- [ ] **Step 4: Add `full` to `main.py:build_recognizer`**

In `inference/app/main.py`, extend the selector (after the `bib` branch, before the `raise`):

```python
    if name == "full":
        from app.bib_recognizer import build_full_recognizer

        return build_full_recognizer()
```

Update the docstring line to: `'stub' needs no weights (dev/CI); 'bib' loads YOLOX + RapidOCR; 'full' adds the CLIP clothing/scene encoder (prod).`

- [ ] **Step 5: Add a models-gated full-path no-disk test (append to `tests/test_no_disk_writes.py`)**

```python
import pytest
from app.bib_config import YOLOX_WEIGHTS_PATH


@pytest.mark.models
def test_full_recognizer_writes_nothing_to_disk(monkeypatch):
    from app.clip_config import CLIP_WEIGHTS_PATH
    if not (YOLOX_WEIGHTS_PATH.exists() and CLIP_WEIGHTS_PATH.exists()):
        pytest.skip("weights absent")

    real_open = open

    def _guarded_open(file, mode="r", *args, **kwargs):
        if any(flag in mode for flag in ("w", "a", "x", "+")):
            raise AssertionError(f"disk write attempted: {file!r} mode={mode!r}")
        return real_open(file, mode, *args, **kwargs)

    from app.bib_recognizer import build_full_recognizer
    recognizer = build_full_recognizer()               # loads weights before the guard
    monkeypatch.setattr("builtins.open", _guarded_open)
    recognizer.recognize(Image.open(io.BytesIO(_jpeg())))
```

- [ ] **Step 6: Run the fast suite (no weights)**

Run: `SKIP_SCHEMA_REBUILD=1 python -m pytest -v -m "not models"`
Expected: PASS — all non-models tests green (selection stub/unknown, zero_shot, clothing_scene fakes, existing bib-parser/crop/etc.)

- [ ] **Step 7: Run the full suite (with weights, if present)**

Run: `SKIP_SCHEMA_REBUILD=1 python -m pytest -v`
Expected: PASS — models tests run when YOLOX+CLIP weights + generated JSON are present, else SKIP

- [ ] **Step 8: Commit**

```bash
git add inference/app/bib_recognizer.py inference/app/main.py inference/tests/test_recognizer_selection.py inference/tests/test_no_disk_writes.py
git commit -m "117 - Phase 2: Phase1Recognizer -> CompositeRecognizer; add RECOGNIZER=full selection"
```

---

### Task 7: Eval harness extension

Report per-field clothing/scene precision/recall alongside bibs against hand-labelled photos.

**Files:**
- Modify: `inference/eval/run_eval.py`
- Create: `inference/eval/labels_clothing.example.json`

**Interfaces:**
- Consumes: `build_full_recognizer()`, the JSON labels file (`{filename: {"colors":[...], "types":[...], "scenes":[...], "bibs":[...]}}`).
- Produces: printed per-field precision/recall + a false-positive list.

- [ ] **Step 1: Write the example labels file**

```json
// inference/eval/labels_clothing.example.json
{
  "5f38445c.preview.jpg": {
    "colors": ["red", "black"],
    "types": ["t-shirt", "shorts"],
    "scenes": ["on-course/running"],
    "bibs": ["2236"]
  }
}
```

- [ ] **Step 2: Rewrite `run_eval.py` to score all four fields**

```python
"""Offline eval: run the real full recognizer over labelled photos, report
per-field precision/recall. Real photos are PII and must not be committed.

Labels schema: {filename: {colors:[...], types:[...], scenes:[...], bibs:[...]}}."""

import argparse
import json
from pathlib import Path

from PIL import Image

from app.bib_recognizer import build_full_recognizer

_FIELDS = ("colors", "types", "scenes", "bibs")


def _predicted(result, field, min_bib_conf):
    if field == "colors":
        return {s.value for s in result.clothing_colors}
    if field == "types":
        return {s.value for s in result.clothing_types}
    if field == "scenes":
        return {s.value for s in result.scenes}
    return {s.value for s in result.bibs if s.confidence >= min_bib_conf}


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--images", required=True, type=Path)
    ap.add_argument("--labels", required=True, type=Path)
    ap.add_argument("--min-bib-conf", type=float, default=0.80)
    args = ap.parse_args()

    labels = json.loads(args.labels.read_text())
    recognizer = build_full_recognizer()

    agg = {f: {"tp": 0, "fp": 0, "fn": 0, "fps": []} for f in _FIELDS}
    for filename, expected in labels.items():
        img = Image.open(args.images / filename)
        img.load()
        result = recognizer.recognize(img)
        for field in _FIELDS:
            predicted = _predicted(result, field, args.min_bib_conf)
            exp = set(expected.get(field, []))
            for value in predicted:
                if value in exp:
                    agg[field]["tp"] += 1
                else:
                    agg[field]["fp"] += 1
                    agg[field]["fps"].append((filename, value))
            agg[field]["fn"] += len(exp - predicted)

    for field in _FIELDS:
        a = agg[field]
        p = a["tp"] / (a["tp"] + a["fp"]) if (a["tp"] + a["fp"]) else 0.0
        r = a["tp"] / (a["tp"] + a["fn"]) if (a["tp"] + a["fn"]) else 0.0
        print(f"[{field}] TP={a['tp']} FP={a['fp']} FN={a['fn']} "
              f"precision={p:.3f} recall={r:.3f}")
        for filename, value in a["fps"]:
            print(f"    FP {filename}: {value!r}")


if __name__ == "__main__":
    main()
```

- [ ] **Step 3: Smoke-check the harness parses (no photos needed)**

Run: `SKIP_SCHEMA_REBUILD=1 python -c "import ast; ast.parse(open('eval/run_eval.py').read()); print('parses ok')"`
Expected: `parses ok`

(Full run requires the gitignored PII photos + weights: `python eval/run_eval.py --images eval/images --labels eval/labels_clothing.json` — record precision/recall in the metrics summary and calibrate `clip_config` floors/margins recall-lean.)

- [ ] **Step 4: Commit**

```bash
git add inference/eval/run_eval.py inference/eval/labels_clothing.example.json
git commit -m "117 - Phase 2: eval harness reports per-field clothing/scene precision/recall"
```

---

### Task 8: Deploy `full` to prod

Flip the prod inference service to the real clothing/scene recognizer.

**Files:**
- Modify: `compose.prod.yaml:66`

**Interfaces:** none (compose config).

- [ ] **Step 1: Flip the RECOGNIZER value**

In `compose.prod.yaml`, change the `inference` service env from `RECOGNIZER: "bib"` to:

```yaml
      RECOGNIZER: "full"
```

Leave `mem_limit: 2g` / `cpus: 2` unchanged (int8 encoder fits within headroom).

- [ ] **Step 2: Validate compose still parses**

Run (from repo root): `docker compose -f compose.prod.yaml config >/dev/null && echo "compose ok"`
Expected: `compose ok`

- [ ] **Step 3: Commit**

```bash
git add compose.prod.yaml
git commit -m "117 - Phase 2: deploy RECOGNIZER=full (real clothing/scene) to prod"
```

---

## Self-Review

**Spec coverage:**
- Per-person attribution reusing Phase 1 crops → Task 5. ✓
- Extensible classifier registry / named encoders → Tasks 1–2 (`ZeroShotClassifier` + `load_classifier` decoupled from any specific encoder); `build_clothing_scene_recognizer` wires per-field classifiers → Task 5. ✓
- Precomputed committed text embeddings (JSON) → Tasks 2–3. ✓
- CLIP ViT-B/32 image encoder, int8, baked at build (pinned URL/SHA) → Task 4. ✓
- Emission: top-k + margin + per-field floor; recall-lean → Task 1 + `clip_config`. ✓
- `RECOGNIZER=stub|bib|full`; CompositeRecognizer refactor → Task 6. ✓
- Eval per-field precision/recall, committed labels/harness, gitignored PII → Task 7. ✓
- Deploy prod `full` → Task 8. ✓
- In-memory-only assertion extended to full path → Task 6 Step 5. ✓
- No PHP changes, contract unchanged → no PHP task (correct). ✓

**Placeholder scan:** The only intentional placeholders are the pinned CLIP URL/bytes/SHA in Task 4 Step 1 (`<PIN_...>`) — these are values that can only be fixed against the chosen HF export at implementation time, exactly as Phase 1 pinned YOLOX. Flagged as an open item in the spec. No other TBDs.

**Type consistency:** `ZeroShotClassifier(field, labels, matrix).classify(emb) -> list[Score]` used consistently in Tasks 1/2/5. `CompositeRecognizer(bib_component, clothing_scene_component)` with `recognize_bibs`/`recognize` contracts consistent across Tasks 5/6. `ClipImageEncoder.encode -> (512,) float32 unit-norm` consistent in Tasks 4/5. `build_full_recognizer` produced in Task 6, consumed in Task 7. ✓
