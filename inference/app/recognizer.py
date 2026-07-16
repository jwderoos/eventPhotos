from dataclasses import dataclass, field
from typing import Protocol

from PIL import Image

from app.vocabulary import VOCABULARY


@dataclass(frozen=True)
class Score:
    value: str
    confidence: float


@dataclass(frozen=True)
class RawResult:
    clothing_colors: list[Score] = field(default_factory=list)
    clothing_types: list[Score] = field(default_factory=list)
    scenes: list[Score] = field(default_factory=list)
    bibs: list[Score] = field(default_factory=list)


class Recognizer(Protocol):
    def recognize(self, image: Image.Image) -> RawResult: ...


# Nearest fixed-palette colour name for the image's average RGB.
_PALETTE: dict[str, tuple[int, int, int]] = {
    "black": (0, 0, 0), "white": (255, 255, 255), "grey": (128, 128, 128),
    "red": (220, 20, 20), "orange": (255, 128, 0), "yellow": (240, 220, 30),
    "green": (30, 160, 60), "blue": (30, 80, 220), "purple": (130, 40, 190),
    "pink": (240, 130, 190), "brown": (120, 70, 30), "beige": (220, 200, 160),
}


class StubRecognizer:
    """Deterministic, weight-free stand-in for v1 wiring and tests.

    PLUG POINTS for production models (each returns list[Score] in-vocabulary):
      * clothing colour/type + scene -> zero-shot CLIP / FashionCLIP over VOCABULARY
      * bibs -> YOLO bib-detector crop -> PaddleOCR digit recognition
    Swap StubRecognizer for the real implementation of the Recognizer protocol;
    main.py depends only on the protocol.
    """

    def recognize(self, image: Image.Image) -> RawResult:
        rgb = image.convert("RGB").resize((1, 1)).getpixel((0, 0))
        colour = min(
            _PALETTE,
            key=lambda name: sum((a - b) ** 2 for a, b in zip(_PALETTE[name], rgb)),
        )
        return RawResult(
            clothing_colors=[Score(colour, 0.90)],
            clothing_types=[Score("t-shirt", 0.85)],
            scenes=[Score("on-course/running", 0.70)],
            bibs=[],  # stub emits no bibs; real OCR fills this
        )
