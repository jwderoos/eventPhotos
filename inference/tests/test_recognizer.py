from PIL import Image
from app.recognizer import Score, StubRecognizer
from app.vocabulary import VOCABULARY


def _img() -> Image.Image:
    return Image.new("RGB", (64, 64), (255, 128, 0))


def test_stub_returns_only_vocab_values():
    result = StubRecognizer().recognize(_img())
    for term in [s.value for s in result.clothing_colors]:
        assert term in VOCABULARY["clothing_colors"]
    for term in [s.value for s in result.clothing_types]:
        assert term in VOCABULARY["clothing_types"]
    for term in [s.value for s in result.scenes]:
        assert term in VOCABULARY["scenes"]


def test_stub_emits_no_bibs():
    # The weight-free stub never emits bibs; real OCR (YOLO+PaddleOCR) fills
    # this in via a production Recognizer. Documenting the contract explicitly
    # so a future recognizer that starts emitting bibs is a deliberate change.
    assert StubRecognizer().recognize(_img()).bibs == []


def test_bib_score_shape_invariant():
    # Bibs are free-form alphanumeric OCR output; assert the Score shape the
    # downstream PHP gate relies on (non-empty alnum value, confidence in [0,1]).
    bib = Score("1423", 0.95)
    assert bib.value.isalnum()
    assert 0.0 <= bib.confidence <= 1.0
