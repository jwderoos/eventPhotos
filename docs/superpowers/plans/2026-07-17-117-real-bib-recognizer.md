# Real Bib Recognizer (Phase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the bib half of the deterministic `StubRecognizer` behind the inference service with a real, CPU-only recognizer that extracts race **bib numbers** from event photos via person-detection → torso-crop → OCR → strict format filtering.

**Architecture:** A new set of small, independently-testable units under `inference/app/` sits behind the existing `Recognizer` protocol: `PersonDetector` (YOLOX ONNX) → `torso_crop()` → `TextReader` (RapidOCR ONNX) → `BibParser` (the precision core). `BibRecognizer` orchestrates them; `Phase1Recognizer` composes real bibs with the existing stub's clothing/colour/scene. `main.py` selects the implementation from a `RECOGNIZER` env flag so the fast test suite needs no model weights. The HTTP contract and the entire PHP side are unchanged.

**Tech Stack:** Python 3.12 / FastAPI / onnxruntime (CPU) / `rapidocr-onnxruntime==1.4.4` (bundles PP-OCRv4 ONNX) / YOLOX-tiny ONNX / NumPy / Pillow / pytest. All model weights Apache-2.0.

## Global Constraints

- **CPU-only, self-hosted, in the TrueNAS Docker stack.** No GPU. Minutes/photo is an acceptable SLA; optimise for precision over latency.
- **In-memory only.** The image and all intermediate crops stay in PIL/NumPy buffers — never written to disk. A test asserts this.
- **Contract unchanged:** `POST /extract` (raw JPEG) → `{clothing_colors, clothing_types, scenes, bibs}` of `{value, confidence}`. Every clothing/colour/scene `value` in the fixed `VOCABULARY`; `bibs` free-form. The PHP side keeps the ≥0.80 bib gate, per-event toggle, and suppress-list — **do not** add these to the service.
- **Boundary (#109, hard):** no faces/biometrics, no landmarks/embeddings, no age/gender/demographic inference, no background text/signage/plates. Person detection is used **only** for region-gating, never identity. Extract the **bib number only — never the printed first name.**
- **Input is the PREVIEW derivative** (~1280 px long edge). The recognizer must work on it.
- **License hygiene (hard):** ship only Apache-2.0 weights. Never Ultralytics YOLOv5/v8/v11 (AGPL), SVHN-trained, or RBNR-trained models.
- **Weights delivery:** baked into the Docker image at build time (pinned URL + size/sha256 check). Not committed to git. RapidOCR models arrive inside the pip wheel.
- **This repo does not auto-commit** (CLAUDE.md). The "Commit" steps below are for the human operator; the implementing agent must NOT run `git commit`. Commit messages must contain the issue number `117`.
- **Quality gates:** the Python service is gated by `pytest`; PHP gates (phpstan/phpcs/rector/phpunit) are untouched because there are no PHP changes. Run `cd inference && python -m pytest -v` after each task.
- Branch: `feature/117-real-bib-recognizer` (already created).

## File Structure

**Create (Python service):**
- `inference/app/bib_config.py` — all tunable constants (regex, bounds, geometry fractions, thresholds, model path) in one place for calibration.
- `inference/app/bib_parser.py` — `TextLine`, `BibParser` (format/geometry/confidence filter). Pure; no weights.
- `inference/app/crop.py` — `torso_crop()`. Pure geometry.
- `inference/app/detect.py` — `Box`, `PersonDetector` (YOLOX ONNX + letterbox + grid/stride decode + NMS).
- `inference/app/ocr.py` — `TextReader` (RapidOCR wrapper → `list[TextLine]`).
- `inference/app/bib_recognizer.py` — `BibRecognizer` (orchestration, dedup), `Phase1Recognizer` (composite implementing `Recognizer`).
- `inference/scripts/fetch_weights.sh` — downloads YOLOX-tiny ONNX with size+sha256 verification (used locally and by the Dockerfile).
- `inference/app/weights/.gitkeep` — keeps the (gitignored) weights dir present.
- `inference/eval/run_eval.py`, `inference/eval/labels.example.json`, `inference/eval/README.md`, `inference/eval/.gitignore` — eval harness; real photos never committed.

**Modify:**
- `inference/pyproject.toml` — runtime deps + register the `models` pytest marker.
- `inference/app/main.py` — select `RECOGNIZER` from env (`stub`|`bib`).
- `inference/Dockerfile` — run `fetch_weights.sh` at build; keep `app/weights` in the image.
- `inference/.dockerignore` — exclude `eval`, keep `app/weights`.
- `inference/.gitignore` (create if absent) — ignore `app/weights/*.onnx`.
- `compose.yaml` — add `RECOGNIZER: "stub"` default to the dev `inference` service.
- `compose.prod.yaml` — add the `inference` service (RECOGNIZER=bib, 2g/2cpu), `INFERENCE_SERVICE_URL` on php+worker, worker depends_on.

**Test (Python):**
- `inference/tests/test_bib_parser.py`, `test_crop.py`, `test_detect_decode.py`, `test_ocr_models.py`, `test_bib_recognizer.py`, `test_detect_models.py`, `test_recognizer_selection.py`, `test_no_disk_writes.py`.

---

### Task 1: Tunable config + `BibParser` (the precision core)

**Files:**
- Create: `inference/app/bib_config.py`
- Create: `inference/app/bib_parser.py`
- Test: `inference/tests/test_bib_parser.py`

**Interfaces:**
- Produces:
  - `bib_config` module constants: `BIB_REGEX: re.Pattern`, `OCR_CONF_FLOOR: float`, `MIN_TEXT_HEIGHT_FRAC: float`, plus torso/detector constants used by later tasks (`TORSO_X_INSET`, `TORSO_TOP_FRAC`, `TORSO_BOTTOM_FRAC`, `PERSON_SCORE_THR`, `NMS_IOU`, `YOLOX_INPUT_SIZE`, `YOLOX_WEIGHTS_PATH`).
  - `TextLine(text: str, confidence: float, height_px: float)` — frozen dataclass. `height_px` is the pixel height of the OCR text box.
  - `BibParser().parse(lines: list[TextLine], crop_height_px: float) -> list[Score]` — returns in-crop bib-number candidates as `app.recognizer.Score(value, confidence)`. `confidence` is the OCR line confidence.

- [ ] **Step 1: Write the failing test**

`inference/tests/test_bib_parser.py`:
```python
import re

from app.bib_parser import BibParser, TextLine
from app.recognizer import Score


CROP_H = 1000.0
TALL = 100.0  # 10% of crop height, above MIN_TEXT_HEIGHT_FRAC


def _parse(text, conf=0.9, height=TALL):
    return BibParser().parse([TextLine(text, conf, height)], CROP_H)


def test_plain_numeric_bib_accepted():
    result = _parse("2236")
    assert result == [Score("2236", 0.9)]


def test_alphanumeric_and_hyphen_bibs_accepted():
    assert _parse("A-142")[0].value == "A-142"
    assert _parse("10K-88")[0].value == "10K-88"


def test_name_line_rejected_no_digit():
    # The printed first name must never be emitted (boundary + no-name rule).
    assert _parse("JOOST") == []


def test_lowercase_rejected():
    assert _parse("abc123") == []


def test_name_and_number_on_one_line_keeps_only_number():
    # OCR sometimes returns "JOOST 2236" as a single line; tokenise on whitespace.
    result = _parse("JOOST 2236")
    assert [s.value for s in result] == ["2236"]


def test_low_confidence_rejected():
    assert _parse("2236", conf=0.30) == []


def test_tiny_text_rejected_by_geometry():
    # 1% of crop height — below MIN_TEXT_HEIGHT_FRAC — likely distant/background.
    assert _parse("2236", height=10.0) == []


def test_overlong_token_rejected():
    assert _parse("1234567890123") == []


def test_dedup_is_not_done_here_returns_each_line():
    parser = BibParser()
    lines = [TextLine("2236", 0.9, TALL), TextLine("2236", 0.8, TALL)]
    assert len(parser.parse(lines, CROP_H)) == 2  # dedup happens in BibRecognizer
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd inference && python -m pytest tests/test_bib_parser.py -v`
Expected: FAIL — `ModuleNotFoundError: No module named 'app.bib_parser'`.

- [ ] **Step 3: Write minimal implementation**

`inference/app/bib_config.py`:
```python
"""Tunable constants for the bib recognizer. Calibrated against the real eval
photos (see inference/eval/). Changing these changes precision/recall — keep the
rationale in the design spec (docs/superpowers/specs/2026-07-17-117-...)."""

import re
from pathlib import Path

# --- BibParser (precision core) ---
# Optional 0-4 char uppercase/digit prefix, optional single hyphen, then 1-6
# digits. Requires a trailing digit run, so an all-letters name ("JOOST") never
# matches. Examples that match: "2236", "A-142", "10K-88".
BIB_REGEX: re.Pattern = re.compile(r"^[A-Z0-9]{0,4}-?[0-9]{1,6}$")
OCR_CONF_FLOOR: float = 0.50          # coarse pre-filter; PHP applies the 0.80 gate
MIN_TEXT_HEIGHT_FRAC: float = 0.06    # text box height / crop height

# --- torso_crop geometry (fractions of the person box) ---
TORSO_X_INSET: float = 0.10           # trim 10% off each side -> central 80% width
TORSO_TOP_FRAC: float = 0.15          # below the head
TORSO_BOTTOM_FRAC: float = 0.60       # above the knees

# --- PersonDetector (YOLOX-tiny) ---
PERSON_SCORE_THR: float = 0.50
NMS_IOU: float = 0.45
YOLOX_INPUT_SIZE: tuple[int, int] = (416, 416)   # nano/tiny export size
YOLOX_WEIGHTS_PATH: Path = Path(__file__).parent / "weights" / "yolox_tiny.onnx"
```

`inference/app/bib_parser.py`:
```python
from dataclasses import dataclass

from app.bib_config import BIB_REGEX, MIN_TEXT_HEIGHT_FRAC, OCR_CONF_FLOOR
from app.recognizer import Score


@dataclass(frozen=True)
class TextLine:
    text: str
    confidence: float
    height_px: float


class BibParser:
    """Turns OCR text lines from one crop into validated bib-number candidates.

    Region-gating (OCR only inside a torso crop) already removed most background
    text; this is the second line of defence. Rules (all must pass), per token:
      1. matches BIB_REGEX (uppercase/digits/one hyphen, ends in a digit run)
      2. OCR confidence >= OCR_CONF_FLOOR
      3. text box height >= MIN_TEXT_HEIGHT_FRAC of the crop height
    Never emits the printed first name (rule 1 requires a trailing digit run).
    Dedup across crops is BibRecognizer's job, not this class's.
    """

    def parse(self, lines: list[TextLine], crop_height_px: float) -> list[Score]:
        out: list[Score] = []
        min_height = MIN_TEXT_HEIGHT_FRAC * crop_height_px
        for line in lines:
            if line.confidence < OCR_CONF_FLOOR:
                continue
            if line.height_px < min_height:
                continue
            for token in line.text.split():
                if BIB_REGEX.match(token):
                    out.append(Score(token, line.confidence))
        return out
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd inference && python -m pytest tests/test_bib_parser.py -v`
Expected: PASS (9 tests).

- [ ] **Step 5: Commit (human runs)**

```bash
git add inference/app/bib_config.py inference/app/bib_parser.py inference/tests/test_bib_parser.py
git commit -m "117 - bib recognizer: tunable config + BibParser precision filter (no weights)"
```

---

### Task 2: `torso_crop()` geometry

**Files:**
- Create: `inference/app/crop.py`
- Test: `inference/tests/test_crop.py`

**Interfaces:**
- Consumes: `bib_config` torso constants.
- Produces: `torso_crop(image: PIL.Image.Image, box: Box) -> PIL.Image.Image` where `Box` is defined in Task 3. To avoid a circular import, `crop.py` takes a lightweight `BoxLike` protocol with float attrs `x1, y1, x2, y2`. Returns the torso sub-region as a new in-memory PIL image.

- [ ] **Step 1: Write the failing test**

`inference/tests/test_crop.py`:
```python
from dataclasses import dataclass

from PIL import Image

from app.crop import torso_crop


@dataclass
class _Box:
    x1: float
    y1: float
    x2: float
    y2: float


def test_torso_crop_is_central_lower_band():
    img = Image.new("RGB", (1000, 1000))
    # Person box spanning the whole image; torso = central 80% width,
    # vertical 15%-60% of the box.
    crop = torso_crop(img, _Box(0, 0, 1000, 1000))
    assert crop.size == (800, 450)  # width 0.8*1000, height (0.60-0.15)*1000


def test_torso_crop_offset_box():
    img = Image.new("RGB", (1000, 1000))
    crop = torso_crop(img, _Box(200, 100, 600, 900))  # 400 wide, 800 tall
    # width: 0.8*400=320 ; height: (0.60-0.15)*800=360
    assert crop.size == (320, 360)


def test_torso_crop_clamps_to_image_bounds():
    img = Image.new("RGB", (100, 100))
    # Box partly outside the image; crop must stay within bounds and be non-empty.
    crop = torso_crop(img, _Box(-50, -50, 150, 150))
    assert crop.size[0] > 0 and crop.size[1] > 0
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd inference && python -m pytest tests/test_crop.py -v`
Expected: FAIL — `ModuleNotFoundError: No module named 'app.crop'`.

- [ ] **Step 3: Write minimal implementation**

`inference/app/crop.py`:
```python
from typing import Protocol

from PIL import Image

from app.bib_config import TORSO_BOTTOM_FRAC, TORSO_TOP_FRAC, TORSO_X_INSET


class BoxLike(Protocol):
    x1: float
    y1: float
    x2: float
    y2: float


def torso_crop(image: Image.Image, box: BoxLike) -> Image.Image:
    """Central-lower band of a person box, where a worn bib sits. In-memory only."""
    w = box.x2 - box.x1
    h = box.y2 - box.y1
    left = box.x1 + TORSO_X_INSET * w
    right = box.x2 - TORSO_X_INSET * w
    top = box.y1 + TORSO_TOP_FRAC * h
    bottom = box.y1 + TORSO_BOTTOM_FRAC * h

    img_w, img_h = image.size
    left = max(0, min(int(round(left)), img_w))
    right = max(left + 1, min(int(round(right)), img_w))
    top = max(0, min(int(round(top)), img_h))
    bottom = max(top + 1, min(int(round(bottom)), img_h))
    return image.crop((left, top, right, bottom))
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd inference && python -m pytest tests/test_crop.py -v`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit (human runs)**

```bash
git add inference/app/crop.py inference/tests/test_crop.py
git commit -m "117 - bib recognizer: torso crop geometry"
```

---

### Task 3: `PersonDetector` — deps, weights fetch, YOLOX decode

This task adds the ONNX runtime deps, the weight-fetch tooling, and the detector. The pure decode/NMS math is unit-tested without weights; a `models`-marked smoke test exercises the real ONNX session.

**Files:**
- Modify: `inference/pyproject.toml`
- Create: `inference/scripts/fetch_weights.sh`
- Create: `inference/app/weights/.gitkeep`
- Create: `inference/.gitignore`
- Create: `inference/app/detect.py`
- Test: `inference/tests/test_detect_decode.py` (no weights)
- Test: `inference/tests/test_detect_models.py` (`models` marker)

**Interfaces:**
- Consumes: `bib_config` detector constants.
- Produces:
  - `Box(x1: float, y1: float, x2: float, y2: float, score: float)` — frozen dataclass, coords in original-image pixels.
  - `decode_yolox(output: np.ndarray, input_hw: tuple[int,int]) -> np.ndarray` — turns raw `[1,N,85]` output into `[N,6]` rows `[x1,y1,x2,y2,score,cls]` in **model-input** pixel space (person class only kept by caller). Pure function.
  - `nms(boxes: np.ndarray, scores: np.ndarray, iou_thr: float) -> list[int]` — indices kept. Pure function.
  - `PersonDetector(weights_path=YOLOX_WEIGHTS_PATH)` with `detect(image: PIL.Image.Image) -> list[Box]`.

- [ ] **Step 1: Add runtime deps + register the `models` marker**

Edit `inference/pyproject.toml` — set `dependencies` and add the marker:
```toml
[project]
name = "eventphotos-inference"
version = "0.1.0"
requires-python = ">=3.12"
dependencies = [
    "fastapi>=0.115",
    "uvicorn[standard]>=0.30",
    "pillow>=10.4",
    "pydantic>=2.8",
    "numpy>=1.26",
    "onnxruntime>=1.17",
    "rapidocr-onnxruntime==1.4.4",
]

[project.optional-dependencies]
test = ["pytest>=8.2", "httpx>=0.27", "httpx2>=2.0"]

[tool.pytest.ini_options]
pythonpath = ["."]
markers = [
    "models: requires model weights present (skipped when absent)",
]
```
Then install locally: `cd inference && pip install -e ".[test]"`.

- [ ] **Step 2: Write the weight-fetch script + gitignore**

`inference/scripts/fetch_weights.sh`:
```bash
#!/usr/bin/env sh
# Downloads the YOLOX-tiny ONNX person detector (Apache-2.0) into app/weights/.
# Immutable GitHub release asset. Verified size guards against a bad/partial pull.
set -eu

DEST="$(dirname "$0")/../app/weights/yolox_tiny.onnx"
URL="https://github.com/Megvii-BaseDetection/YOLOX/releases/download/0.1.1rc0/yolox_tiny.onnx"
EXPECTED_BYTES="20219662"

mkdir -p "$(dirname "$DEST")"
if [ -f "$DEST" ] && [ "$(wc -c < "$DEST")" = "$EXPECTED_BYTES" ]; then
    echo "yolox_tiny.onnx already present and correct size."
else
    echo "Downloading yolox_tiny.onnx ..."
    curl -fSL "$URL" -o "$DEST"
fi

ACTUAL_BYTES="$(wc -c < "$DEST")"
if [ "$ACTUAL_BYTES" != "$EXPECTED_BYTES" ]; then
    echo "SIZE MISMATCH: expected $EXPECTED_BYTES got $ACTUAL_BYTES" >&2
    exit 1
fi
echo "sha256: $(sha256sum "$DEST" 2>/dev/null || shasum -a 256 "$DEST")"
echo "OK."
```
Make it executable: `chmod +x inference/scripts/fetch_weights.sh`.

`inference/.gitignore`:
```
app/weights/*.onnx
```

`inference/app/weights/.gitkeep`: (empty file)

- [ ] **Step 3: Fetch the weights locally (needed for the models test)**

Run: `cd inference && sh scripts/fetch_weights.sh`
Expected: prints `sha256: <hash>` and `OK.`, and `app/weights/yolox_tiny.onnx` exists at 20,219,662 bytes.

- [ ] **Step 4: Write the failing tests**

`inference/tests/test_detect_decode.py` (pure math, no weights):
```python
import numpy as np

from app.detect import decode_yolox, nms


def test_nms_suppresses_overlapping_boxes():
    boxes = np.array([[0, 0, 10, 10], [1, 1, 11, 11], [100, 100, 110, 110]], dtype=float)
    scores = np.array([0.9, 0.8, 0.7])
    keep = nms(boxes, scores, iou_thr=0.45)
    assert 0 in keep and 2 in keep and 1 not in keep


def test_decode_shapes_and_center_to_corner():
    # One 1x1 grid at stride 8 on a 8x8 input: single prediction.
    # Raw row: cx_off=0, cy_off=0, w=log-space 0 -> exp(0)*8=8, h=8, obj=1, cls0=1.
    raw = np.zeros((1, 1, 85), dtype=np.float32)
    raw[0, 0, 4] = 1.0   # obj_conf
    raw[0, 0, 5] = 1.0   # class 0 (person)
    out = decode_yolox(raw, input_hw=(8, 8))
    assert out.shape == (1, 6)
    x1, y1, x2, y2, score, cls = out[0]
    # center (0.5*8, 0.5*8)=(4,4), w=h=8 -> corners (0,0,8,8)
    assert abs(x1 - 0) < 1e-4 and abs(y2 - 8) < 1e-4
    assert abs(score - 1.0) < 1e-4 and int(cls) == 0
```

`inference/tests/test_detect_models.py` (`models` marker):
```python
import numpy as np
import pytest
from PIL import Image

from app.bib_config import YOLOX_INPUT_SIZE, YOLOX_WEIGHTS_PATH
from app.detect import PersonDetector

pytestmark = pytest.mark.models


@pytest.mark.skipif(not YOLOX_WEIGHTS_PATH.exists(), reason="yolox weights absent")
def test_model_input_is_expected_size():
    import onnxruntime as ort

    sess = ort.InferenceSession(str(YOLOX_WEIGHTS_PATH))
    shape = sess.get_inputs()[0].shape  # e.g. [1, 3, 416, 416]
    assert tuple(shape[2:]) == YOLOX_INPUT_SIZE, f"model input {shape} != {YOLOX_INPUT_SIZE}"


@pytest.mark.skipif(not YOLOX_WEIGHTS_PATH.exists(), reason="yolox weights absent")
def test_detect_returns_no_persons_on_blank_image():
    # Blank image has no people; detector must return an empty list without error.
    detector = PersonDetector()
    assert detector.detect(Image.new("RGB", (640, 480), (128, 128, 128))) == []
```

- [ ] **Step 5: Run tests to verify they fail**

Run: `cd inference && python -m pytest tests/test_detect_decode.py tests/test_detect_models.py -v`
Expected: FAIL — `ModuleNotFoundError: No module named 'app.detect'`.

- [ ] **Step 6: Write minimal implementation**

`inference/app/detect.py`:
```python
from dataclasses import dataclass

import numpy as np
import onnxruntime as ort
from PIL import Image

from app.bib_config import (
    NMS_IOU,
    PERSON_SCORE_THR,
    YOLOX_INPUT_SIZE,
    YOLOX_WEIGHTS_PATH,
)

_PERSON_CLASS = 0
_PAD_VALUE = 114


@dataclass(frozen=True)
class Box:
    x1: float
    y1: float
    x2: float
    y2: float
    score: float


def _letterbox(image: Image.Image, input_hw: tuple[int, int]) -> tuple[np.ndarray, float]:
    """Resize preserving aspect ratio, pad to input_hw with 114. Returns
    (CHW float32 BGR array, ratio). No normalization (YOLOX preproc)."""
    ih, iw = input_hw
    rgb = np.asarray(image.convert("RGB"))
    h, w = rgb.shape[:2]
    ratio = min(ih / h, iw / w)
    nh, nw = int(round(h * ratio)), int(round(w * ratio))
    resized = np.asarray(Image.fromarray(rgb).resize((nw, nh), Image.BILINEAR))
    canvas = np.full((ih, iw, 3), _PAD_VALUE, dtype=np.uint8)
    canvas[:nh, :nw] = resized
    bgr = canvas[:, :, ::-1]                      # RGB -> BGR
    chw = bgr.transpose(2, 0, 1).astype(np.float32)
    return chw[np.newaxis, ...], ratio


def decode_yolox(output: np.ndarray, input_hw: tuple[int, int]) -> np.ndarray:
    """Raw [1,N,85] -> [N,6] rows [x1,y1,x2,y2,score,cls] in input-pixel space."""
    preds = output[0]
    ih, iw = input_hw
    grids, strides = [], []
    for stride in (8, 16, 32):
        gh, gw = ih // stride, iw // stride
        xv, yv = np.meshgrid(np.arange(gw), np.arange(gh))
        grid = np.stack((xv, yv), axis=-1).reshape(-1, 2)
        grids.append(grid)
        strides.append(np.full((grid.shape[0], 1), stride))
    grid = np.concatenate(grids, axis=0)
    stride = np.concatenate(strides, axis=0)

    xy = (preds[:, :2] + grid) * stride
    wh = np.exp(preds[:, 2:4]) * stride
    obj = preds[:, 4:5]
    cls_scores = preds[:, 5:]
    cls_id = cls_scores.argmax(axis=1)
    cls_conf = cls_scores.max(axis=1)
    score = (obj[:, 0] * cls_conf)

    x1 = xy[:, 0] - wh[:, 0] / 2
    y1 = xy[:, 1] - wh[:, 1] / 2
    x2 = xy[:, 0] + wh[:, 0] / 2
    y2 = xy[:, 1] + wh[:, 1] / 2
    return np.stack([x1, y1, x2, y2, score, cls_id.astype(np.float32)], axis=1)


def nms(boxes: np.ndarray, scores: np.ndarray, iou_thr: float) -> list[int]:
    x1, y1, x2, y2 = boxes[:, 0], boxes[:, 1], boxes[:, 2], boxes[:, 3]
    areas = (x2 - x1) * (y2 - y1)
    order = scores.argsort()[::-1]
    keep: list[int] = []
    while order.size > 0:
        i = int(order[0])
        keep.append(i)
        xx1 = np.maximum(x1[i], x1[order[1:]])
        yy1 = np.maximum(y1[i], y1[order[1:]])
        xx2 = np.minimum(x2[i], x2[order[1:]])
        yy2 = np.minimum(y2[i], y2[order[1:]])
        w = np.maximum(0.0, xx2 - xx1)
        h = np.maximum(0.0, yy2 - yy1)
        inter = w * h
        iou = inter / (areas[i] + areas[order[1:]] - inter)
        order = order[1:][iou <= iou_thr]
    return keep


class PersonDetector:
    def __init__(self, weights_path=YOLOX_WEIGHTS_PATH):
        self._session = ort.InferenceSession(
            str(weights_path), providers=["CPUExecutionProvider"]
        )
        self._input_name = self._session.get_inputs()[0].name

    def detect(self, image: Image.Image) -> list[Box]:
        blob, ratio = _letterbox(image, YOLOX_INPUT_SIZE)
        output = self._session.run(None, {self._input_name: blob})[0]
        rows = decode_yolox(output, YOLOX_INPUT_SIZE)
        persons = rows[(rows[:, 5] == _PERSON_CLASS) & (rows[:, 4] >= PERSON_SCORE_THR)]
        if persons.shape[0] == 0:
            return []
        keep = nms(persons[:, :4], persons[:, 4], NMS_IOU)
        boxes = []
        for i in keep:
            x1, y1, x2, y2, score = persons[i, :5]
            boxes.append(Box(x1 / ratio, y1 / ratio, x2 / ratio, y2 / ratio, float(score)))
        return boxes
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `cd inference && python -m pytest tests/test_detect_decode.py tests/test_detect_models.py -v`
Expected: PASS. If `test_model_input_is_expected_size` fails, the real model input differs from 416 — update `YOLOX_INPUT_SIZE` in `bib_config.py` to the reported dims (this resolves the acquisition-report's flagged 416 assumption) and re-run.

- [ ] **Step 8: Commit (human runs)**

```bash
git add inference/pyproject.toml inference/scripts/fetch_weights.sh inference/.gitignore inference/app/weights/.gitkeep inference/app/detect.py inference/tests/test_detect_decode.py inference/tests/test_detect_models.py
git commit -m "117 - bib recognizer: YOLOX person detector (deps, weight fetch, decode+NMS)"
```

---

### Task 4: `TextReader` (RapidOCR wrapper)

**Files:**
- Create: `inference/app/ocr.py`
- Test: `inference/tests/test_ocr_models.py` (`models` marker — RapidOCR models ship in the wheel, so this runs whenever deps are installed)

**Interfaces:**
- Consumes: `TextLine` (Task 1).
- Produces: `TextReader()` with `read(image: PIL.Image.Image) -> list[TextLine]`. Wraps `rapidocr_onnxruntime.RapidOCR`, converting each `[box, text, score]` triple to a `TextLine` whose `height_px` is `max(y) - min(y)` over the box corners.

- [ ] **Step 1: Write the failing test**

`inference/tests/test_ocr_models.py`:
```python
import pytest
from PIL import Image, ImageDraw, ImageFont

from app.bib_parser import TextLine
from app.ocr import TextReader

pytestmark = pytest.mark.models


def _rendered_number(text: str) -> Image.Image:
    img = Image.new("RGB", (240, 120), (255, 255, 255))
    draw = ImageDraw.Draw(img)
    try:
        font = ImageFont.truetype("DejaVuSans-Bold.ttf", 72)
    except OSError:
        font = ImageFont.load_default()
    draw.text((30, 20), text, fill=(0, 0, 0), font=font)
    return img


def test_reads_rendered_digits():
    lines = TextReader().read(_rendered_number("1234"))
    assert any("1234" in line.text for line in lines)
    assert all(isinstance(line, TextLine) for line in lines)
    assert all(line.height_px > 0 for line in lines)


def test_blank_image_returns_empty_list():
    assert TextReader().read(Image.new("RGB", (200, 100), (255, 255, 255))) == []
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd inference && python -m pytest tests/test_ocr_models.py -v`
Expected: FAIL — `ModuleNotFoundError: No module named 'app.ocr'`.

- [ ] **Step 3: Write minimal implementation**

`inference/app/ocr.py`:
```python
import numpy as np
from PIL import Image

from app.bib_parser import TextLine


class TextReader:
    """Wraps RapidOCR (PP-OCRv4 ONNX, bundled in the wheel). In-memory only."""

    def __init__(self):
        from rapidocr_onnxruntime import RapidOCR

        self._engine = RapidOCR()

    def read(self, image: Image.Image) -> list[TextLine]:
        bgr = np.asarray(image.convert("RGB"))[:, :, ::-1]
        result, _ = self._engine(bgr)
        if not result:
            return []
        lines: list[TextLine] = []
        for box, text, score in result:
            ys = [pt[1] for pt in box]
            height_px = float(max(ys) - min(ys))
            lines.append(TextLine(text=str(text), confidence=float(score), height_px=height_px))
        return lines
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd inference && python -m pytest tests/test_ocr_models.py -v`
Expected: PASS (2 tests). (First run may take a few seconds while RapidOCR initialises its bundled models.)

- [ ] **Step 5: Commit (human runs)**

```bash
git add inference/app/ocr.py inference/tests/test_ocr_models.py
git commit -m "117 - bib recognizer: RapidOCR TextReader wrapper"
```

---

### Task 5: `BibRecognizer` + `Phase1Recognizer` (orchestration + dedup)

Orchestration is unit-tested with injected fakes — no weights required. This is where per-frame dedup lives.

**Files:**
- Create: `inference/app/bib_recognizer.py`
- Test: `inference/tests/test_bib_recognizer.py`

**Interfaces:**
- Consumes: `PersonDetector`/`Box` (Task 3), `torso_crop` (Task 2), `TextReader` (Task 4), `BibParser`/`TextLine` (Task 1), `StubRecognizer`/`RawResult`/`Score` (existing `app.recognizer`).
- Produces:
  - `BibRecognizer(detector, reader, parser)` with `recognize_bibs(image) -> list[Score]` — detects persons, crops each torso, OCRs, parses, then **dedups by value keeping max confidence**.
  - `Phase1Recognizer(bib_recognizer, stub)` implementing the `Recognizer` protocol: `recognize(image) -> RawResult` with real `bibs` and the stub's `clothing_colors`/`clothing_types`/`scenes`.
  - `build_bib_recognizer() -> Phase1Recognizer` — factory wiring the real detector/reader/parser/stub (used by `main.py` for `RECOGNIZER=bib`).

- [ ] **Step 1: Write the failing test**

`inference/tests/test_bib_recognizer.py`:
```python
from PIL import Image

from app.bib_parser import TextLine
from app.bib_recognizer import BibRecognizer, Phase1Recognizer
from app.detect import Box
from app.recognizer import StubRecognizer


class _FakeDetector:
    def __init__(self, boxes):
        self._boxes = boxes

    def detect(self, image):
        return self._boxes


class _FakeReader:
    def __init__(self, lines_per_call):
        self._lines = list(lines_per_call)

    def read(self, image):
        return self._lines.pop(0) if self._lines else []


def _img():
    return Image.new("RGB", (1000, 1000))


def test_recognize_bibs_extracts_number_and_drops_name():
    from app.bib_parser import BibParser

    detector = _FakeDetector([Box(0, 0, 1000, 1000, 0.9)])
    reader = _FakeReader([[TextLine("JOOST", 0.9, 100.0), TextLine("2236", 0.95, 100.0)]])
    rec = BibRecognizer(detector, reader, BibParser())
    scores = rec.recognize_bibs(_img())
    assert [s.value for s in scores] == ["2236"]


def test_dedup_keeps_max_confidence_across_crops():
    from app.bib_parser import BibParser

    detector = _FakeDetector([Box(0, 0, 500, 1000, 0.9), Box(500, 0, 1000, 1000, 0.9)])
    reader = _FakeReader([[TextLine("2236", 0.81, 100.0)], [TextLine("2236", 0.93, 100.0)]])
    rec = BibRecognizer(detector, reader, BibParser())
    scores = rec.recognize_bibs(_img())
    assert len(scores) == 1
    assert scores[0].value == "2236"
    assert abs(scores[0].confidence - 0.93) < 1e-6


def test_no_persons_means_no_bibs():
    from app.bib_parser import BibParser

    rec = BibRecognizer(_FakeDetector([]), _FakeReader([]), BibParser())
    assert rec.recognize_bibs(_img()) == []


def test_phase1_composes_stub_for_non_bib_fields():
    from app.bib_parser import BibParser

    detector = _FakeDetector([Box(0, 0, 1000, 1000, 0.9)])
    reader = _FakeReader([[TextLine("2236", 0.95, 100.0)]])
    phase1 = Phase1Recognizer(BibRecognizer(detector, reader, BibParser()), StubRecognizer())
    result = phase1.recognize(_img())
    assert [s.value for s in result.bibs] == ["2236"]
    # non-bib fields come from the stub (non-empty, in-vocabulary)
    assert result.clothing_colors and result.clothing_types and result.scenes
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd inference && python -m pytest tests/test_bib_recognizer.py -v`
Expected: FAIL — `ModuleNotFoundError: No module named 'app.bib_recognizer'`.

- [ ] **Step 3: Write minimal implementation**

`inference/app/bib_recognizer.py`:
```python
from PIL import Image

from app.bib_parser import BibParser
from app.crop import torso_crop
from app.recognizer import RawResult, Score, StubRecognizer


class BibRecognizer:
    def __init__(self, detector, reader, parser: BibParser):
        self._detector = detector
        self._reader = reader
        self._parser = parser

    def recognize_bibs(self, image: Image.Image) -> list[Score]:
        best: dict[str, float] = {}
        for box in self._detector.detect(image):
            crop = torso_crop(image, box)
            lines = self._reader.read(crop)
            for score in self._parser.parse(lines, float(crop.size[1])):
                if score.value not in best or score.confidence > best[score.value]:
                    best[score.value] = score.confidence
        return [Score(value, conf) for value, conf in best.items()]


class Phase1Recognizer:
    """Real bibs + stub clothing/colour/scene (Phase 2 replaces the stub half)."""

    def __init__(self, bib_recognizer: BibRecognizer, stub: StubRecognizer):
        self._bibs = bib_recognizer
        self._stub = stub

    def recognize(self, image: Image.Image) -> RawResult:
        stub_result = self._stub.recognize(image)
        return RawResult(
            clothing_colors=stub_result.clothing_colors,
            clothing_types=stub_result.clothing_types,
            scenes=stub_result.scenes,
            bibs=self._bibs.recognize_bibs(image),
        )


def build_bib_recognizer() -> Phase1Recognizer:
    from app.detect import PersonDetector
    from app.ocr import TextReader

    return Phase1Recognizer(
        BibRecognizer(PersonDetector(), TextReader(), BibParser()),
        StubRecognizer(),
    )
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd inference && python -m pytest tests/test_bib_recognizer.py -v`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit (human runs)**

```bash
git add inference/app/bib_recognizer.py inference/tests/test_bib_recognizer.py
git commit -m "117 - bib recognizer: orchestration + per-frame dedup + Phase1 composite"
```

---

### Task 6: Wire `RECOGNIZER` env selection into `main.py` + no-disk-writes guard

**Files:**
- Modify: `inference/app/main.py`
- Test: `inference/tests/test_recognizer_selection.py`
- Test: `inference/tests/test_no_disk_writes.py`

**Interfaces:**
- Consumes: `build_bib_recognizer` (Task 5), `StubRecognizer` (existing).
- Produces: `build_recognizer(name: str)` returning the selected `Recognizer`; module-level `RECOGNIZER` built from `os.environ["RECOGNIZER"]` (default `"stub"`). `/extract` behaviour unchanged.

- [ ] **Step 1: Write the failing tests**

`inference/tests/test_recognizer_selection.py`:
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
def test_bib_selection_builds_phase1():
    from app.bib_config import YOLOX_WEIGHTS_PATH
    if not YOLOX_WEIGHTS_PATH.exists():
        pytest.skip("yolox weights absent")
    from app.bib_recognizer import Phase1Recognizer

    assert isinstance(build_recognizer("bib"), Phase1Recognizer)
```

`inference/tests/test_no_disk_writes.py`:
```python
import io

from fastapi.testclient import TestClient
from PIL import Image

from app import main


def _jpeg():
    buf = io.BytesIO()
    Image.new("RGB", (64, 64), (255, 128, 0)).save(buf, format="JPEG")
    return buf.getvalue()


def test_extract_writes_nothing_to_disk(monkeypatch):
    # Any attempt to open a file for writing during /extract is a boundary breach.
    real_open = open

    def _guarded_open(file, mode="r", *args, **kwargs):
        if any(flag in mode for flag in ("w", "a", "x", "+")):
            raise AssertionError(f"disk write attempted: {file!r} mode={mode!r}")
        return real_open(file, mode, *args, **kwargs)

    monkeypatch.setattr("builtins.open", _guarded_open)
    client = TestClient(main.app)
    resp = client.post("/extract", content=_jpeg(), headers={"Content-Type": "image/jpeg"})
    assert resp.status_code == 200
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd inference && python -m pytest tests/test_recognizer_selection.py -v`
Expected: FAIL — `ImportError: cannot import name 'build_recognizer' from 'app.main'`.

- [ ] **Step 3: Write minimal implementation**

Edit the top of `inference/app/main.py` — replace the recognizer wiring (lines 6–13, the `from app.recognizer import ...`, `RECOGNIZER = StubRecognizer()` block) with:
```python
import io
import os

from fastapi import FastAPI, HTTPException, Request
from PIL import Image, UnidentifiedImageError

from app.recognizer import RawResult, Recognizer, Score, StubRecognizer
from app.schemas import ExtractResponse, ScoreOut
from app.vocabulary import VOCABULARY

app = FastAPI(title="EventPhotos Inference", version="0.2.0")


def build_recognizer(name: str) -> Recognizer:
    """Select the recognizer implementation. 'stub' needs no weights (dev/CI);
    'bib' loads the real YOLOX + RapidOCR pipeline (prod)."""
    if name == "stub":
        return StubRecognizer()
    if name == "bib":
        from app.bib_recognizer import build_bib_recognizer

        return build_bib_recognizer()
    raise ValueError(f"unknown RECOGNIZER={name!r} (expected 'stub' or 'bib')")


RECOGNIZER: Recognizer = build_recognizer(os.environ.get("RECOGNIZER", "stub"))
```
Leave `_UNPROCESSABLE`, `health`, `_in_vocab`, and `extract` exactly as they are.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd inference && python -m pytest tests/test_recognizer_selection.py tests/test_no_disk_writes.py -v`
Expected: PASS.

- [ ] **Step 5: Run the full suite (stub path, no weights needed)**

Run: `cd inference && python -m pytest -m "not models" -v`
Expected: PASS — every non-`models` test green, proving the fast suite needs no weights.

- [ ] **Step 6: Commit (human runs)**

```bash
git add inference/app/main.py inference/tests/test_recognizer_selection.py inference/tests/test_no_disk_writes.py
git commit -m "117 - inference: RECOGNIZER env selection (stub|bib) + no-disk-write guard test"
```

---

### Task 7: Dockerfile bakes weights + dev compose default + build smoke test

**Files:**
- Modify: `inference/Dockerfile`
- Modify: `inference/.dockerignore`
- Modify: `compose.yaml`

**Interfaces:**
- Produces: an image that contains `app/weights/yolox_tiny.onnx` and RapidOCR's bundled models, runs the real pipeline when `RECOGNIZER=bib`, and serves `/health`.

- [ ] **Step 1: Update the Dockerfile to fetch weights at build**

Replace `inference/Dockerfile` with:
```dockerfile
FROM python:3.12-slim

# curl for the build-time weight fetch; libgl/glib for onnxruntime + image libs.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl libgl1 libglib2.0-0 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /srv
COPY pyproject.toml ./
# Runtime deps only — the `test` extra (pytest/httpx) is dev/CI tooling.
RUN pip install --no-cache-dir "."
COPY app ./app
COPY scripts ./scripts

# Bake the YOLOX-tiny ONNX (Apache-2.0) into the image. RapidOCR models already
# ship inside the pip wheel, so no separate OCR download is needed.
RUN sh scripts/fetch_weights.sh

EXPOSE 8000
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
```

- [ ] **Step 2: Update `.dockerignore`**

Replace `inference/.dockerignore` with:
```
tests
eval
__pycache__
*.pyc
.pytest_cache
.venv
*.egg-info
```
(Note: `app/weights` is intentionally NOT ignored — but the `.onnx` is fetched at build, not copied, so either way the image gets it. `eval` is excluded to keep PII/local images out of the image.)

- [ ] **Step 3: Add the `RECOGNIZER` default to the dev compose inference service**

In `compose.yaml`, add an `environment` block to the `inference` service (after `dockerfile: Dockerfile`, before `restart:`):
```yaml
    environment:
      RECOGNIZER: "${RECOGNIZER:-stub}"
```
(Dev stays on the stub by default; set `RECOGNIZER=bib` in the shell to try the real pipeline locally.)

- [ ] **Step 4: Build and smoke-test the real pipeline in the image**

Run:
```bash
docker compose build inference
docker compose run --rm -e RECOGNIZER=bib inference \
  python -c "from app.main import build_recognizer; r=build_recognizer('bib'); print(type(r).__name__)"
```
Expected: prints `Phase1Recognizer` (proves weights baked + real pipeline constructs inside the image).

- [ ] **Step 5: Verify health still serves**

Run:
```bash
docker compose up -d inference && sleep 8
docker compose exec -T inference python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://localhost:8000/health').read()==b'{\"status\":\"ok\"}' else 1)" && echo OK
docker compose stop inference
```
Expected: `OK`.

- [ ] **Step 6: Commit (human runs)**

```bash
git add inference/Dockerfile inference/.dockerignore compose.yaml
git commit -m "117 - inference image: bake YOLOX weights at build + dev RECOGNIZER default"
```

---

### Task 8: Deploy the inference service to prod (`compose.prod.yaml`)

**Files:**
- Modify: `compose.prod.yaml`

**Interfaces:**
- Produces: a prod `inference` service (RECOGNIZER=bib, 2g/2cpu, healthcheck) reachable at `http://inference:8000`; `php` and `worker` get `INFERENCE_SERVICE_URL`; `worker` depends on it.

- [ ] **Step 1: Add the `inference` service**

In `compose.prod.yaml`, add under `services:` (after the `worker` service, mirroring its restart/stop policy; §Global-Constraints resource caps):
```yaml
  inference:
    build:
      context: ./inference
      dockerfile: Dockerfile
    image: eventphotos-inference:latest
    environment:
      RECOGNIZER: "bib"
    restart: unless-stopped
    stop_signal: SIGTERM
    stop_grace_period: 15s
    healthcheck:
      test: ["CMD", "python", "-c", "import urllib.request; urllib.request.urlopen('http://localhost:8000/health')"]
      timeout: 5s
      retries: 5
      start_period: 30s
    # CPU-only inference; the box has ~2 GB headroom. Single uvicorn worker,
    # minutes/photo SLA — PHP worker replicas drive throughput.
    mem_limit: 2g
    cpus: 2
```

- [ ] **Step 2: Add `INFERENCE_SERVICE_URL` to `php` and `worker`**

In `compose.prod.yaml`, add to the `php` service `environment:` block (after `APP_DEBUG: "0"`):
```yaml
      INFERENCE_SERVICE_URL: "http://inference:8000"
```
Add the same line to the `worker` service `environment:` block, and add the dependency to `worker`'s `depends_on:`:
```yaml
      inference:
        condition: service_started
```

- [ ] **Step 3: Validate the compose file parses**

Run: `docker compose -f compose.prod.yaml config >/dev/null && echo OK`
Expected: `OK` (no YAML/schema error). (This only validates structure; a real deploy happens on the NAS via `./deploy.sh`.)

- [ ] **Step 4: Commit (human runs)**

```bash
git add compose.prod.yaml
git commit -m "117 - prod: add inference service (RECOGNIZER=bib, 2g/2cpu) + wire php/worker"
```

---

### Task 9: Eval harness + calibration against real photos

Produces the measurement that the acceptance criterion (precision-first) depends on. Real photos are **never committed**.

**Files:**
- Create: `inference/eval/run_eval.py`
- Create: `inference/eval/labels.example.json`
- Create: `inference/eval/README.md`
- Create: `inference/eval/.gitignore`

**Interfaces:**
- Consumes: `build_bib_recognizer` (Task 5).
- Produces: a CLI `python eval/run_eval.py --images <dir> --labels <labels.json>` that prints per-photo predicted vs expected bibs and overall **precision / recall / false-positive list**.

- [ ] **Step 1: Write the eval `.gitignore` (PII stays out of git)**

`inference/eval/.gitignore`:
```
images/
labels.json
metrics.txt
```

- [ ] **Step 2: Write the labels example + README**

`inference/eval/labels.example.json`:
```json
{
  "5f38445c.preview.jpg": ["2236"],
  "eed0edb5.preview.jpg": ["492", "1724", "2646"]
}
```

`inference/eval/README.md`:
```markdown
# Bib recognizer eval

Real race photos are PII and are **never committed**. Only this harness, the
example labels, and a metrics summary belong in git.

## Run
1. Extract the event export originals/previews into `eval/images/` (gitignored).
2. Hand-label bibs into `eval/labels.json` (see `labels.example.json`); key =
   filename, value = list of the *numbers* visible on legible foreground bibs.
3. Install deps and fetch weights: `pip install -e ".[test]" && sh scripts/fetch_weights.sh`
4. `python eval/run_eval.py --images eval/images --labels eval/labels.json`

Reports precision / recall / the false-positive list. Acceptance is
**precision-first**: target >= ~0.95 of emitted bibs correct on the
foreground-legible subset. If precision is low, tighten `app/bib_config.py`
(`OCR_CONF_FLOOR`, `MIN_TEXT_HEIGHT_FRAC`, `BIB_REGEX`); if recall is very low,
loosen geometry/confidence or consider YOLOX-tiny→ a fine-tuned bib detector
(future work). Record the final numbers in `metrics.txt` and paste a summary
into the #117 PR.
```

- [ ] **Step 3: Write the eval runner**

`inference/eval/run_eval.py`:
```python
"""Offline eval: run the real bib pipeline over labelled photos, report
precision/recall. Real photos are PII and must not be committed."""

import argparse
import json
from pathlib import Path

from PIL import Image

from app.bib_recognizer import build_bib_recognizer


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--images", required=True, type=Path)
    ap.add_argument("--labels", required=True, type=Path)
    ap.add_argument("--min-conf", type=float, default=0.80,
                    help="mirror the PHP bib gate (default 0.80)")
    args = ap.parse_args()

    labels = json.loads(args.labels.read_text())
    recognizer = build_bib_recognizer()

    tp = fp = fn = 0
    false_positives: list[tuple[str, str]] = []
    for filename, expected in labels.items():
        img = Image.open(args.images / filename)
        img.load()
        scores = recognizer.recognize(img).bibs
        predicted = {s.value for s in scores if s.confidence >= args.min_conf}
        expected_set = set(expected)
        for value in predicted:
            if value in expected_set:
                tp += 1
            else:
                fp += 1
                false_positives.append((filename, value))
        fn += len(expected_set - predicted)
        print(f"{filename}: predicted={sorted(predicted)} expected={sorted(expected_set)}")

    precision = tp / (tp + fp) if (tp + fp) else 0.0
    recall = tp / (tp + fn) if (tp + fn) else 0.0
    print(f"\nTP={tp} FP={fp} FN={fn}")
    print(f"precision={precision:.3f} recall={recall:.3f}")
    if false_positives:
        print("\nFALSE POSITIVES (must be ~zero for precision-first acceptance):")
        for filename, value in false_positives:
            print(f"  {filename}: {value!r}")


if __name__ == "__main__":
    main()
```

- [ ] **Step 4: Run the eval against the real export (human/agent, local only)**

Run:
```bash
cd inference
# Extract previews from ~/Downloads/event-race-with-bibs-f1r5c7.zip into eval/images/,
# then hand-label eval/labels.json.
pip install -e ".[test]" && sh scripts/fetch_weights.sh
python eval/run_eval.py --images eval/images --labels eval/labels.json | tee eval/metrics.txt
```
Expected: per-photo lines + a precision/recall summary. **Iterate `app/bib_config.py` until precision ≥ ~0.95 on the foreground-legible subset with a near-empty false-positive list.** If `BIB_REGEX`/thresholds change, re-run Task 1's tests (`python -m pytest tests/test_bib_parser.py -v`) to keep them green.

- [ ] **Step 5: Commit the harness (NOT the images/labels/metrics)**

```bash
git add inference/eval/run_eval.py inference/eval/labels.example.json inference/eval/README.md inference/eval/.gitignore
# If bib_config.py was tuned during calibration, include it:
git add inference/app/bib_config.py
git commit -m "117 - bib recognizer: eval harness + calibrated thresholds (PII photos gitignored)"
```

---

## Self-Review

**1. Spec coverage** (against `docs/superpowers/specs/2026-07-17-117-real-bib-recognizer-design.md`):
- Architecture units (PersonDetector, torso_crop, TextReader, BibParser, BibRecognizer, Phase1Recognizer) → Tasks 1–5. ✓
- Contract unchanged; bibs pass through; PHP untouched → no PHP tasks; `main.py` `/extract` body left intact (Task 6). ✓
- In-memory only → asserted (Task 6, `test_no_disk_writes`). ✓
- Precision core rules (charset+digit, length, geometry, confidence; number-only/name-drop) → Task 1. ✓
- Models Apache-2.0, baked at build, YOLOX-tiny + RapidOCR bundled → Tasks 3, 7. ✓
- `RECOGNIZER=stub|bib`, fast suite needs no weights, `models` marker → Tasks 3, 6. ✓
- Prod deploy (inference service, 2g/2cpu, env, depends_on) → Task 8. ✓
- Eval harness, precision-first target, photos not committed → Task 9. ✓
- Boundary: person-detect region-gating only, no faces/name/background-text → Tasks 1, 3, design non-goals; no face/landmark code anywhere. ✓

**2. Placeholder scan:** No TBD/TODO. The one acquisition uncertainty (YOLOX input dim = 416) is resolved by a real assertion test (Task 3 Step 7) that tells the engineer exactly what to change if it differs — not a placeholder. The YOLOX sha256 is printed by `fetch_weights.sh` and integrity is guarded by the verified exact byte size (20,219,662) against an immutable release asset. Calibration values in `bib_config.py` are concrete defaults, tuned in Task 9. ✓

**3. Type consistency:** `Score(value, confidence)` and `RawResult(...)` are the existing `app.recognizer` types, used identically throughout. `TextLine(text, confidence, height_px)` defined in Task 1, produced by Task 4, consumed by Tasks 1/5. `Box(x1,y1,x2,y2,score)` defined in Task 3, consumed by Task 2 (via `BoxLike`) and Task 5. `BibParser.parse(lines, crop_height_px)`, `TextReader.read(image)`, `PersonDetector.detect(image)`, `BibRecognizer.recognize_bibs(image)`, `Phase1Recognizer.recognize(image)`, `build_recognizer(name)`, `build_bib_recognizer()` — names stable across the tasks that define/use them. ✓

## Notes
- Run order matters only for imports; Tasks 1→5 build the units bottom-up, 6 wires them, 7–8 ship them, 9 measures. Each task's tests pass on its own.
- The `models`-marked tests (Tasks 3, 4, 6) run locally once `sh scripts/fetch_weights.sh` has fetched YOLOX and deps are installed; CI without weights runs `pytest -m "not models"` and stays green.
- If precision can't reach target on person-crop OCR, the documented upgrade path is a bib-specific detector fine-tuned on the user's own labelled photos (clean license) — a separate follow-on, not this plan.
