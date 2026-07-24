import numpy as np
import pytest
from PIL import Image

from app.bib_config import YOLOX_INPUT_SIZE, YOLOX_WEIGHTS_PATH
from app.detect import PersonDetector

pytestmark = pytest.mark.models


@pytest.mark.skipif(not YOLOX_WEIGHTS_PATH.exists(), reason="yolox weights absent")
def test_model_input_is_expected_size():
    import onnxruntime as ort

    sess = ort.InferenceSession(str(YOLOX_WEIGHTS_PATH))
    shape = sess.get_inputs()[0].shape  # e.g. [1, 3, 416, 416]
    assert tuple(shape[2:]) == YOLOX_INPUT_SIZE, f"model input {shape} != {YOLOX_INPUT_SIZE}"


@pytest.mark.skipif(not YOLOX_WEIGHTS_PATH.exists(), reason="yolox weights absent")
def test_detect_returns_no_persons_on_blank_image():
    # Blank image has no people; detector must return an empty list without error.
    detector = PersonDetector()
    assert detector.detect(Image.new("RGB", (640, 480), (128, 128, 128))) == []
