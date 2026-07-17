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
