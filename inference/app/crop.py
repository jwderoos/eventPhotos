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
