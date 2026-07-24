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
MIN_TEXT_HEIGHT_FRAC: float = 0.025   # text box height / crop height (calibrated #117: bibs are a
#                                       small vertical slice of a tall torso crop; tuned alongside
#                                       TORSO_BOTTOM_FRAC=0.80 — a taller crop needs a smaller fraction)
MIN_BIB_LENGTH: int = 3               # default: drop sub-3-char OCR fragments of occluded/edge bibs.
#                                       Calibrated #117 for an event whose bibs are 3-4 digits; it is a
#                                       PER-EVENT tunable — LOWER it (e.g. 1) for small events (<100
#                                       runners / short or 1-2 digit bib numbers) or recall zeroes out.

# --- torso_crop geometry (fractions of the person box) ---
TORSO_X_INSET: float = 0.10           # trim 10% off each side -> central 80% width
TORSO_TOP_FRAC: float = 0.15          # below the head
TORSO_BOTTOM_FRAC: float = 0.80       # to the hips (calibrated #117: bibs are worn LOW at the
#                                       waist/hip ~60-75% down the body; 0.60 clipped them off the
#                                       bottom of the crop, missing clear foreground bibs)

# --- PersonDetector (YOLOX-tiny) ---
PERSON_SCORE_THR: float = 0.50
NMS_IOU: float = 0.45
YOLOX_INPUT_SIZE: tuple[int, int] = (416, 416)   # nano/tiny export size
YOLOX_WEIGHTS_PATH: Path = Path(__file__).parent / "weights" / "yolox_tiny.onnx"
