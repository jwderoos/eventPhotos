from PIL import Image

from app.bib_parser import TextLine
from app.bib_recognizer import BibRecognizer, CompositeRecognizer
from app.detect import Box
from app.recognizer import StubRecognizer


class _FakeDetector:
    def __init__(self, boxes):
        self._boxes = boxes

    def detect(self, image):
        return self._boxes


class _FakeReader:
    def __init__(self, lines_per_call):
        self._lines = list(lines_per_call)

    def read(self, image):
        return self._lines.pop(0) if self._lines else []


def _img():
    return Image.new("RGB", (1000, 1000))


def test_recognize_bibs_extracts_number_and_drops_name():
    from app.bib_parser import BibParser

    detector = _FakeDetector([Box(0, 0, 1000, 1000, 0.9)])
    reader = _FakeReader([[TextLine("JOOST", 0.9, 100.0), TextLine("2236", 0.95, 100.0)]])
    rec = BibRecognizer(detector, reader, BibParser())
    scores = rec.recognize_bibs(_img())
    assert [s.value for s in scores] == ["2236"]


def test_dedup_keeps_max_confidence_across_crops():
    from app.bib_parser import BibParser

    detector = _FakeDetector([Box(0, 0, 500, 1000, 0.9), Box(500, 0, 1000, 1000, 0.9)])
    reader = _FakeReader([[TextLine("2236", 0.81, 100.0)], [TextLine("2236", 0.93, 100.0)]])
    rec = BibRecognizer(detector, reader, BibParser())
    scores = rec.recognize_bibs(_img())
    assert len(scores) == 1
    assert scores[0].value == "2236"
    assert abs(scores[0].confidence - 0.93) < 1e-6


def test_no_persons_means_no_bibs():
    from app.bib_parser import BibParser

    rec = BibRecognizer(_FakeDetector([]), _FakeReader([]), BibParser())
    assert rec.recognize_bibs(_img()) == []


def test_composite_composes_stub_for_non_bib_fields():
    from app.bib_parser import BibParser

    detector = _FakeDetector([Box(0, 0, 1000, 1000, 0.9)])
    reader = _FakeReader([[TextLine("2236", 0.95, 100.0)]])
    composite = CompositeRecognizer(BibRecognizer(detector, reader, BibParser()), StubRecognizer())
    result = composite.recognize(_img())
    assert [s.value for s in result.bibs] == ["2236"]
    # non-bib fields come from the stub (non-empty, in-vocabulary)
    assert result.clothing_colors and result.clothing_types and result.scenes
