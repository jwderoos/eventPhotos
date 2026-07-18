import pytest

from app.main import build_recognizer
from app.recognizer import StubRecognizer


def test_default_and_stub_select_stub():
    assert isinstance(build_recognizer("stub"), StubRecognizer)


def test_unknown_value_raises():
    with pytest.raises(ValueError):
        build_recognizer("nope")


@pytest.mark.models
def test_bib_selection_builds_composite():
    from app.bib_config import YOLOX_WEIGHTS_PATH
    if not YOLOX_WEIGHTS_PATH.exists():
        pytest.skip("yolox weights absent")
    from app.bib_recognizer import CompositeRecognizer
    assert isinstance(build_recognizer("bib"), CompositeRecognizer)


@pytest.mark.models
def test_full_selection_builds_composite():
    from app.bib_config import YOLOX_WEIGHTS_PATH
    from app.clip_config import CLIP_WEIGHTS_PATH
    if not (YOLOX_WEIGHTS_PATH.exists() and CLIP_WEIGHTS_PATH.exists()):
        pytest.skip("weights absent")
    from app.bib_recognizer import CompositeRecognizer
    assert isinstance(build_recognizer("full"), CompositeRecognizer)
