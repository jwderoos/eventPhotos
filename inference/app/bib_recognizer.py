from PIL import Image

from app.bib_parser import BibParser
from app.crop import torso_crop
from app.recognizer import RawResult, Score, StubRecognizer


class BibRecognizer:
    def __init__(self, detector, reader, parser: BibParser):
        self._detector = detector
        self._reader = reader
        self._parser = parser

    def recognize_bibs(self, image: Image.Image) -> list[Score]:
        best: dict[str, float] = {}
        for box in self._detector.detect(image):
            crop = torso_crop(image, box)
            lines = self._reader.read(crop)
            for score in self._parser.parse(lines, float(crop.size[1])):
                if score.value not in best or score.confidence > best[score.value]:
                    best[score.value] = score.confidence
        return [Score(value, conf) for value, conf in best.items()]


class Phase1Recognizer:
    """Real bibs + stub clothing/colour/scene (Phase 2 replaces the stub half)."""

    def __init__(self, bib_recognizer: BibRecognizer, stub: StubRecognizer):
        self._bibs = bib_recognizer
        self._stub = stub

    def recognize(self, image: Image.Image) -> RawResult:
        stub_result = self._stub.recognize(image)
        return RawResult(
            clothing_colors=stub_result.clothing_colors,
            clothing_types=stub_result.clothing_types,
            scenes=stub_result.scenes,
            bibs=self._bibs.recognize_bibs(image),
        )


def build_bib_recognizer() -> Phase1Recognizer:
    from app.detect import PersonDetector
    from app.ocr import TextReader

    return Phase1Recognizer(
        BibRecognizer(PersonDetector(), TextReader(), BibParser()),
        StubRecognizer(),
    )
