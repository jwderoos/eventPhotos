import io

from fastapi.testclient import TestClient
from PIL import Image

from app.main import app
from app.vocabulary import VOCABULARY

client = TestClient(app)


def _jpeg_bytes(colour=(255, 128, 0)) -> bytes:
    buf = io.BytesIO()
    Image.new("RGB", (64, 64), colour).save(buf, format="JPEG")
    return buf.getvalue()


def test_extract_returns_full_schema():
    resp = client.post(
        "/extract", content=_jpeg_bytes(), headers={"Content-Type": "image/jpeg"}
    )
    assert resp.status_code == 200
    body = resp.json()
    assert set(body.keys()) == {"clothing_colors", "clothing_types", "scenes", "bibs"}


def test_extract_values_are_all_in_vocabulary():
    body = client.post(
        "/extract", content=_jpeg_bytes(), headers={"Content-Type": "image/jpeg"}
    ).json()
    for key in ("clothing_colors", "clothing_types", "scenes"):
        for item in body[key]:
            assert item["value"] in VOCABULARY[key]
            assert 0.0 <= item["confidence"] <= 1.0


def test_extract_rejects_non_image():
    resp = client.post(
        "/extract", content=b"not-an-image", headers={"Content-Type": "image/jpeg"}
    )
    assert resp.status_code == 422


def test_extract_drops_out_of_vocabulary_terms(monkeypatch):
    from app import main
    from app.recognizer import RawResult, Score

    class RogueRecognizer:
        def recognize(self, image):
            return RawResult(
                clothing_colors=[Score("orange", 0.9), Score("chartreuse", 0.9)],
            )

    monkeypatch.setattr(main, "RECOGNIZER", RogueRecognizer())
    body = client.post(
        "/extract", content=_jpeg_bytes(), headers={"Content-Type": "image/jpeg"}
    ).json()
    values = [c["value"] for c in body["clothing_colors"]]
    assert "orange" in values
    assert "chartreuse" not in values
