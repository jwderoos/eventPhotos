"""Offline generator for the committed text-embedding JSON (#117 Phase 2).

Run deliberately in a throwaway venv — NOT part of CI or the Docker build:

    python -m venv /tmp/clipgen && . /tmp/clipgen/bin/activate
    pip install torch transformers
    cd inference && python scripts/build_text_embeddings.py

The output MUST be regenerated if CLIP_MODEL_ID or PROMPT_TEMPLATES change; its
embedding space must match the shipped ONNX image encoder (same checkpoint)."""

import json
import sys
from pathlib import Path

import numpy as np
import torch
from transformers import CLIPModel, CLIPTokenizerFast

# Make `app` importable whether run as `python scripts/build_text_embeddings.py`
# (from inference/) or inside the Docker builder stage (WORKDIR /srv).
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app import clip_config  # noqa: E402
from app.vocabulary import VOCABULARY  # noqa: E402
from app.zero_shot import expand_prompts  # noqa: E402


def main() -> None:
    model = CLIPModel.from_pretrained(clip_config.CLIP_MODEL_ID).eval()
    tokenizer = CLIPTokenizerFast.from_pretrained(clip_config.CLIP_MODEL_ID)
    out_dir = clip_config.TEXT_EMBEDDINGS_DIR
    out_dir.mkdir(parents=True, exist_ok=True)

    for field, labels in VOCABULARY.items():
        rows = []
        ordered = sorted(labels)
        for label in ordered:
            prompts = expand_prompts(field, label)
            tokens = tokenizer(prompts, padding=True, return_tensors="pt")
            with torch.no_grad():
                # Explicit projection path (version-robust across transformers):
                # text tower pooled output → text_projection = 512-d text embedding,
                # the same space as the image side's visual_projection.
                text_outputs = model.text_model(**tokens)
                feats = model.text_projection(text_outputs.pooler_output)
            feats = feats / feats.norm(dim=-1, keepdim=True)
            mean = feats.mean(dim=0)
            mean = mean / mean.norm()
            rows.append(mean.cpu().numpy().astype(np.float32))
        matrix = np.stack(rows)
        payload = {
            "model": clip_config.CLIP_MODEL_ID,
            "dim": int(matrix.shape[1]),
            "labels": ordered,
            "embeddings": [[round(float(x), 6) for x in row] for row in matrix],
        }
        path = out_dir / f"{field}.json"
        path.write_text(json.dumps(payload, indent=2))
        print(f"wrote {path} ({matrix.shape[0]} labels x {matrix.shape[1]} dim)")


if __name__ == "__main__":
    main()
