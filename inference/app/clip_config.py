"""Tunable constants for the clothing/scene zero-shot recognizer (#117 Phase 2).
Floors/margins/temperature CALIBRATED recall-lean against a 12-photo hand-labelled
sample from the real event (2026-07-18): colours P=0.66/R=0.56, types P=0.70/R=0.70,
scenes P=0.73/R=0.85. Per-field so colour (inherently weak at colour-naming) stays
looser. Small sample — treat as indicative; re-run inference/eval/run_eval.py after
expanding the labelled set. Metrics: inference/eval/metrics_clothing.md. See the spec
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
# Calibrated 2026-07-18 (see module docstring + eval/metrics_clothing.md).
FLOOR: dict[str, float] = {
    "clothing_colors": 0.12,   # colour-naming is weak; looser floor for recall
    "clothing_types": 0.16,    # P=R=0.70 sweet spot on the sample
    "scenes": 0.20,
}
MARGIN: dict[str, float] = {
    "clothing_colors": 0.35,   # admit multiple garment colours per person
    "clothing_types": 0.25,
    "scenes": 0.20,
}
TOPK: dict[str, int] = {
    "clothing_colors": 3,
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

# Natural-language phrases for compound taxonomy labels — used ONLY for prompt
# generation (the emitted attribute value stays the taxonomy label). Labels not
# listed fall back to their raw form. Fixes CLIP text-quality on slash/hyphen ids.
PROMPT_LABEL_OVERRIDES: dict[str, dict[str, str]] = {
    "clothing_types": {
        "hoodie/sweater": "hoodie or sweater",
        "hat/cap": "hat or cap",
    },
    "scenes": {
        "finish-line": "finish line",
        "on-course/running": "runner on the race course",
        "water-station": "water station",
        "crowd/spectators": "crowd of spectators",
        "medal/podium": "medal ceremony",
    },
}
