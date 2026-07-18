import copy

import numpy as np
import pytest

from app.clothing_scene_recognizer import ClothingSceneRecognizer, FieldSpec
from app.detect import Box
from app.zero_shot import ZeroShotClassifier


def _unit(vec):
    v = np.asarray(vec, dtype=np.float32)
    return v / np.linalg.norm(v)


COLOR_LABELS = ["red", "blue"]
COLOR_MATRIX = np.stack([_unit([1, 0]), _unit([0, 1])])


class FakeDetector:
    def __init__(self, boxes):
        self._boxes = boxes

    def detect(self, image):
        return self._boxes


class FakeEncoder:
    """Returns preset vectors in call order: N crop encodes, then whole-image."""

    def __init__(self, vectors):
        self._vectors = list(vectors)
        self._i = 0

    def encode(self, image):
        vec = self._vectors[self._i]
        self._i += 1
        return vec


def _clf(field, labels, matrix):
    return ZeroShotClassifier(field, labels, matrix)


@pytest.fixture(autouse=True)
def _snapshot_clip_config():
    """Snapshot and restore clip_config dicts to prevent test pollution."""
    from app import clip_config

    # Deep copy the config dicts before each test
    floor_backup = copy.deepcopy(clip_config.FLOOR)
    margin_backup = copy.deepcopy(clip_config.MARGIN)
    topk_backup = copy.deepcopy(clip_config.TOPK)
    temperature_backup = clip_config.TEMPERATURE

    yield

    # Restore after the test
    clip_config.FLOOR.clear()
    clip_config.FLOOR.update(floor_backup)
    clip_config.MARGIN.clear()
    clip_config.MARGIN.update(margin_backup)
    clip_config.TOPK.clear()
    clip_config.TOPK.update(topk_backup)
    clip_config.TEMPERATURE = temperature_backup


def _rec(detector, encoder):
    import app.clip_config as cfg
    cfg.FLOOR["clothing_colors"] = 0.0
    cfg.MARGIN["clothing_colors"] = 0.0   # top-1 only, deterministic
    cfg.TOPK["clothing_colors"] = 1
    cfg.FLOOR["scenes"] = 0.0
    cfg.MARGIN["scenes"] = 0.0
    cfg.TOPK["scenes"] = 1
    scene_labels = ["finish-line", "start"]
    scene_matrix = np.stack([_unit([1, 0]), _unit([0, 1])])
    type_clf = _clf("clothing_types", ["t-shirt"], np.stack([_unit([1.0, 0.0])]))
    fields = {
        "clothing_colors": FieldSpec("clip", _clf("clothing_colors", COLOR_LABELS, COLOR_MATRIX), "person"),
        "clothing_types": FieldSpec("clip", type_clf, "person"),
        "scenes": FieldSpec("clip", _clf("scenes", scene_labels, scene_matrix), "image"),
    }
    return ClothingSceneRecognizer(detector, {"clip": encoder}, fields)


def _img():
    from PIL import Image
    return Image.new("RGB", (100, 100), (0, 0, 0))


def test_unions_colours_across_two_people():
    boxes = [Box(0, 0, 50, 100, 0.9), Box(50, 0, 100, 100, 0.9)]
    # person1 -> red, person2 -> blue, whole-image -> finish-line
    enc = FakeEncoder([_unit([1, 0]), _unit([0, 1]), _unit([1, 0])])
    result = _rec(FakeDetector(boxes), enc).recognize(_img())
    assert {s.value for s in result.clothing_colors} == {"red", "blue"}
    assert {s.value for s in result.scenes} == {"finish-line"}
    assert result.bibs == []


def test_dedups_same_colour_keeping_max_conf():
    boxes = [Box(0, 0, 50, 100, 0.9), Box(50, 0, 100, 100, 0.9)]
    # both people -> red (different strength), whole-image -> start
    enc = FakeEncoder([_unit([1, 0.2]), _unit([1, 0.0]), _unit([0, 1])])
    result = _rec(FakeDetector(boxes), enc).recognize(_img())
    reds = [s for s in result.clothing_colors if s.value == "red"]
    assert len(reds) == 1
    assert {s.value for s in result.scenes} == {"start"}


def test_no_persons_still_yields_scene():
    enc = FakeEncoder([_unit([1, 0])])   # only the whole-image encode happens
    result = _rec(FakeDetector([]), enc).recognize(_img())
    assert result.clothing_colors == []
    assert {s.value for s in result.scenes} == {"finish-line"}
