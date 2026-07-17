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
    # vertical 15%-80% of the box (TORSO_BOTTOM_FRAC=0.80, calibrated #117).
    crop = torso_crop(img, _Box(0, 0, 1000, 1000))
    assert crop.size == (800, 650)  # width 0.8*1000, height (0.80-0.15)*1000


def test_torso_crop_offset_box():
    img = Image.new("RGB", (1000, 1000))
    crop = torso_crop(img, _Box(200, 100, 600, 900))  # 400 wide, 800 tall
    # width: 0.8*400=320 ; height: (0.80-0.15)*800=520
    assert crop.size == (320, 520)


def test_torso_crop_clamps_to_image_bounds():
    img = Image.new("RGB", (100, 100))
    # Box partly outside the image; crop must stay within bounds and be non-empty.
    crop = torso_crop(img, _Box(-50, -50, 150, 150))
    assert crop.size[0] > 0 and crop.size[1] > 0
