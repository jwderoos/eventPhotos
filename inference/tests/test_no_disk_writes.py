import io

import pytest
from fastapi.testclient import TestClient
from PIL import Image

from app import main
from app.bib_config import YOLOX_WEIGHTS_PATH


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
