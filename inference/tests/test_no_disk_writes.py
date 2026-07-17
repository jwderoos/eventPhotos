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
