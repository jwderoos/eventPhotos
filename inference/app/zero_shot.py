import json
from pathlib import Path

import numpy as np

from app import clip_config
from app.recognizer import Score
from app.vocabulary import VOCABULARY


def softmax(x: np.ndarray) -> np.ndarray:
    z = x - np.max(x)
    e = np.exp(z)
    return e / e.sum()


def expand_prompts(field: str, label: str) -> list[str]:
    phrase = clip_config.PROMPT_LABEL_OVERRIDES.get(field, {}).get(label, label)
    return [t.format(label=phrase) for t in clip_config.PROMPT_TEMPLATES[field]]


class ZeroShotClassifier:
    """Scores an L2-normalized image embedding against a field's precomputed
    text-embedding matrix, emitting in-vocab labels via top-k + margin + floor."""

    def __init__(self, field: str, labels: list[str], text_matrix: np.ndarray):
        self._field = field
        self._labels = labels
        self._matrix = np.asarray(text_matrix, dtype=np.float32)

    def classify(self, image_embedding: np.ndarray) -> list[Score]:
        emb = np.asarray(image_embedding, dtype=np.float32)
        sims = self._matrix @ emb                      # cosine (both normalized)
        probs = softmax(sims / clip_config.TEMPERATURE)
        top = float(probs.max())
        floor = clip_config.FLOOR[self._field]
        margin = clip_config.MARGIN[self._field]
        topk = clip_config.TOPK[self._field]

        order = np.argsort(probs)[::-1]
        kept: list[Score] = []
        for i in order:
            p = float(probs[i])
            if p < floor or (top - p) > margin:
                continue
            kept.append(Score(self._labels[i], p))
            if len(kept) >= topk:
                break
        return kept


def load_classifier(field: str, path: Path, expected_dim: int | None = None,
                    expected_model: str | None = None) -> ZeroShotClassifier:
    data = json.loads(Path(path).read_text())
    labels = list(data["labels"])
    dim = int(data["dim"])
    matrix = np.asarray(data["embeddings"], dtype=np.float32)

    if matrix.shape != (len(labels), dim):
        raise ValueError(
            f"{path}: embeddings shape {matrix.shape} != (labels={len(labels)}, dim={dim})"
        )
    if expected_dim is not None and dim != expected_dim:
        raise ValueError(f"{path}: dim {dim} != expected image-encoder dim {expected_dim}")
    if expected_model is not None and data.get("model") != expected_model:
        raise ValueError(
            f"{path}: model {data.get('model')!r} != expected {expected_model!r} "
            f"(text embeddings must come from the same checkpoint as the image encoder)"
        )
    unknown = set(labels) - VOCABULARY[field]
    if unknown:
        raise ValueError(f"{path}: labels not in {field} vocab: {sorted(unknown)}")
    return ZeroShotClassifier(field, labels, matrix)
