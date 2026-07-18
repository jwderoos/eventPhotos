from dataclasses import dataclass

from PIL import Image

from app.crop import person_crop
from app.recognizer import RawResult, Score


def _keep_max(best: dict, score: Score) -> None:
    if score.value not in best or score.confidence > best[score.value]:
        best[score.value] = score.confidence


@dataclass(frozen=True)
class FieldSpec:
    """Routes one output field to a named encoder + a classifier + a scope.
    scope 'person' → classify each detected person's full-box crop (union across
    people); scope 'image' → classify the whole frame once."""
    encoder: str
    classifier: object
    scope: str


class ClothingSceneRecognizer:
    """Per-field zero-shot over a registry of named encoders. v1 wires one CLIP
    encoder for all three fields; adding a second model later (e.g. FashionCLIP
    for clothing) is a registry edit, not a pipeline change. In-memory only;
    person detection is region-gating only (reused from Phase 1)."""

    def __init__(self, detector, encoders: dict, fields: dict):
        self._detector = detector
        self._encoders = encoders          # name -> encoder with .encode(image)
        self._fields = fields              # output-field name -> FieldSpec

    def recognize(self, image: Image.Image) -> RawResult:
        acc: dict[str, dict] = {name: {} for name in self._fields}
        person_fields = {f: s for f, s in self._fields.items() if s.scope == "person"}
        image_fields = {f: s for f, s in self._fields.items() if s.scope == "image"}

        if person_fields:
            needed = {s.encoder for s in person_fields.values()}
            for box in self._detector.detect(image):
                crop = person_crop(image, box)
                embs = {name: self._encoders[name].encode(crop) for name in needed}
                for f, spec in person_fields.items():
                    for score in spec.classifier.classify(embs[spec.encoder]):
                        _keep_max(acc[f], score)

        if image_fields:
            needed = {s.encoder for s in image_fields.values()}
            embs = {name: self._encoders[name].encode(image) for name in needed}
            for f, spec in image_fields.items():
                for score in spec.classifier.classify(embs[spec.encoder]):
                    _keep_max(acc[f], score)

        return RawResult(
            clothing_colors=[Score(v, c) for v, c in acc.get("clothing_colors", {}).items()],
            clothing_types=[Score(v, c) for v, c in acc.get("clothing_types", {}).items()],
            scenes=[Score(v, c) for v, c in acc.get("scenes", {}).items()],
            bibs=[],
        )


def build_clothing_scene_recognizer(detector=None) -> ClothingSceneRecognizer:
    from app.clip_config import CLIP_MODEL_ID, CLIP_WEIGHTS_PATH, TEXT_EMBEDDINGS_DIR
    from app.clip_encoder import ClipImageEncoder
    from app.detect import PersonDetector
    from app.zero_shot import load_classifier

    # Accept a shared PersonDetector so the composite can reuse one YOLOX session
    # across bibs + clothing (avoids loading the weights twice); falls back to its
    # own when built standalone.
    if detector is None:
        detector = PersonDetector()
    encoders = {"clip": ClipImageEncoder(CLIP_WEIGHTS_PATH)}
    fields = {
        "clothing_colors": FieldSpec(
            "clip",
            load_classifier("clothing_colors", TEXT_EMBEDDINGS_DIR / "clothing_colors.json", 512, CLIP_MODEL_ID),
            "person",
        ),
        "clothing_types": FieldSpec(
            "clip",
            load_classifier("clothing_types", TEXT_EMBEDDINGS_DIR / "clothing_types.json", 512, CLIP_MODEL_ID),
            "person",
        ),
        "scenes": FieldSpec(
            "clip",
            load_classifier("scenes", TEXT_EMBEDDINGS_DIR / "scenes.json", 512, CLIP_MODEL_ID),
            "image",
        ),
    }
    return ClothingSceneRecognizer(detector, encoders, fields)
