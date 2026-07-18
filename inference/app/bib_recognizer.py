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


class CompositeRecognizer:
    """Real bibs + a pluggable clothing/scene component. `bib` uses the stub for
    the clothing half; `full` uses the real ClothingSceneRecognizer."""

    def __init__(self, bib_component, clothing_scene_component):
        self._bibs = bib_component
        self._clothing_scene = clothing_scene_component

    def recognize(self, image: Image.Image) -> RawResult:
        cs = self._clothing_scene.recognize(image)
        return RawResult(
            clothing_colors=cs.clothing_colors,
            clothing_types=cs.clothing_types,
            scenes=cs.scenes,
            bibs=self._bibs.recognize_bibs(image),
        )


def build_bib_recognizer() -> CompositeRecognizer:
    from app.detect import PersonDetector
    from app.ocr import TextReader

    return CompositeRecognizer(
        BibRecognizer(PersonDetector(), TextReader(), BibParser()),
        StubRecognizer(),
    )


def build_full_recognizer() -> CompositeRecognizer:
    from app.clothing_scene_recognizer import build_clothing_scene_recognizer
    from app.detect import PersonDetector
    from app.ocr import TextReader

    # One shared YOLOX session for both the bib and clothing/scene components
    # (the detection forward pass still runs per component; sharing avoids a
    # second loaded session — threading the boxes through is a follow-up).
    detector = PersonDetector()
    return CompositeRecognizer(
        BibRecognizer(detector, TextReader(), BibParser()),
        build_clothing_scene_recognizer(detector=detector),
    )
