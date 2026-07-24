import numpy as np
import pytest
from PIL import Image

from app.clip_config import CLIP_WEIGHTS_PATH


pytestmark = pytest.mark.models


@pytest.fixture()
def encoder():
    if not CLIP_WEIGHTS_PATH.exists():
        pytest.skip("clip weights absent")
    from app.clip_encoder import ClipImageEncoder
    return ClipImageEncoder()


def test_encode_returns_normalized_512(encoder):
    img = Image.new("RGB", (300, 200), (200, 30, 30))
    emb = encoder.encode(img)
    assert emb.shape == (512,)
    assert emb.dtype == np.float32
    assert abs(float(np.linalg.norm(emb)) - 1.0) < 1e-4


def test_encode_discriminates_colours(encoder):
    from app.zero_shot import load_classifier
    from app.clip_config import TEXT_EMBEDDINGS_DIR
    clf = load_classifier("clothing_colors", TEXT_EMBEDDINGS_DIR / "clothing_colors.json", 512)
    red = clf.classify(encoder.encode(Image.new("RGB", (224, 224), (220, 20, 20))))
    blue = clf.classify(encoder.encode(Image.new("RGB", (224, 224), (30, 60, 220))))
    assert red and red[0].value == "red"
    assert blue and blue[0].value == "blue"
