import copy

import numpy as np
import pytest

from app.zero_shot import ZeroShotClassifier, softmax, expand_prompts


def _unit(vec):
    v = np.asarray(vec, dtype=np.float32)
    return v / np.linalg.norm(v)


# A toy 3-dim embedding space: three orthogonal "labels".
LABELS = ["red", "blue", "green"]
MATRIX = np.stack([_unit([1, 0, 0]), _unit([0, 1, 0]), _unit([0, 0, 1])])


def _clf(field="clothing_colors"):
    return ZeroShotClassifier(field, LABELS, MATRIX)


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


def test_softmax_sums_to_one():
    out = softmax(np.array([1.0, 2.0, 3.0]))
    assert out.shape == (3,)
    assert abs(float(out.sum()) - 1.0) < 1e-6
    assert out[2] > out[1] > out[0]


def test_top_label_wins_and_is_scored():
    scores = _clf().classify(_unit([0.95, 0.1, 0.1]))
    assert scores, "expected at least the top label"
    assert scores[0].value == "red"
    assert 0.0 < scores[0].confidence <= 1.0


def test_labels_below_floor_are_dropped(monkeypatch):
    # Force a high floor: a near-uniform embedding should clear nothing.
    monkeypatch.setitem(__import__("app.clip_config", fromlist=["FLOOR"]).FLOOR,
                        "clothing_colors", 0.99)
    scores = _clf().classify(_unit([1, 1, 1]))
    assert scores == []


def test_margin_admits_a_close_second():
    import app.clip_config as cfg
    # Wide margin + low floor -> a genuinely close second label is admitted.
    cfg.FLOOR["clothing_colors"] = 0.0
    cfg.MARGIN["clothing_colors"] = 1.0
    cfg.TOPK["clothing_colors"] = 3
    scores = _clf().classify(_unit([1, 0.98, 0.0]))
    values = {s.value for s in scores}
    assert {"red", "blue"} <= values


def test_topk_caps_emitted_labels():
    import app.clip_config as cfg
    cfg.FLOOR["clothing_colors"] = 0.0
    cfg.MARGIN["clothing_colors"] = 1.0
    cfg.TOPK["clothing_colors"] = 1
    scores = _clf().classify(_unit([1, 0.9, 0.8]))
    assert len(scores) == 1
    assert scores[0].value == "red"


def test_expand_prompts_uses_templates_and_label():
    prompts = expand_prompts("clothing_colors", "red")
    assert prompts, "expected at least one prompt"
    assert all("red" in p for p in prompts)


from pathlib import Path

from app.zero_shot import load_classifier

_FIXTURE = Path(__file__).parent / "fixtures" / "toy_embeddings.json"


def test_load_classifier_reads_labels_and_matrix():
    clf = load_classifier("clothing_colors", _FIXTURE)
    scores = clf.classify(_unit([0.95, 0.1, 0.1]))
    assert scores[0].value == "red"


def test_load_classifier_rejects_dim_mismatch():
    with pytest.raises(ValueError, match="dim"):
        load_classifier("clothing_colors", _FIXTURE, expected_dim=512)


def test_load_classifier_rejects_out_of_vocab_label(tmp_path):
    bad = tmp_path / "bad.json"
    bad.write_text('{"model":"toy","dim":1,"labels":["not-a-colour"],'
                   '"embeddings":[[1.0]]}')
    with pytest.raises(ValueError, match="vocab"):
        load_classifier("clothing_colors", bad)


def test_load_classifier_rejects_model_mismatch():
    with pytest.raises(ValueError, match="model"):
        load_classifier("clothing_colors", _FIXTURE, expected_model="openai/clip-vit-base-patch32")
