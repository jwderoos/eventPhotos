"""Export the CLIP ViT-B/32 vision tower to ONNX from the SAME checkpoint used
for the text embeddings (openai/clip-vit-base-patch32), so image and text
embeddings share a space. Output = get_image_features (512-d, projected).

Runs in the Docker builder stage (torch+transformers there), or locally in a
throwaway venv. NOT imported by app/ and NOT in the runtime image."""

import sys
from pathlib import Path

import torch
from transformers import CLIPModel

# Make `app` importable whether run as `python scripts/export_vision_onnx.py`
# (from inference/) or inside the Docker builder stage (WORKDIR /srv).
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app.clip_config import CLIP_INPUT_SIZE, CLIP_MODEL_ID, CLIP_WEIGHTS_PATH  # noqa: E402


class _VisionEmbed(torch.nn.Module):
    """Wraps get_image_features so the exported graph outputs the projected,
    512-d image embedding (same space as get_text_features)."""

    def __init__(self, model: CLIPModel):
        super().__init__()
        self._model = model

    def forward(self, pixel_values):
        # Explicit projection path (version-robust): vision tower pooled output
        # → visual projection = the 512-d image embedding, the same space as the
        # text side's text_projection(pooler_output).
        vision_outputs = self._model.vision_model(pixel_values=pixel_values)
        return self._model.visual_projection(vision_outputs.pooler_output)


def main() -> None:
    model = CLIPModel.from_pretrained(CLIP_MODEL_ID).eval()
    wrapper = _VisionEmbed(model).eval()
    # Fixed batch=1 — the runtime ClipImageEncoder always sends one image; this
    # avoids the exporter's version-dependent dynamic-axes API.
    dummy = torch.zeros(1, 3, CLIP_INPUT_SIZE, CLIP_INPUT_SIZE)
    CLIP_WEIGHTS_PATH.parent.mkdir(parents=True, exist_ok=True)
    torch.onnx.export(
        wrapper,
        dummy,
        str(CLIP_WEIGHTS_PATH),
        input_names=["pixel_values"],
        output_names=["image_embeds"],
        opset_version=17,
    )
    print(f"wrote {CLIP_WEIGHTS_PATH}")


if __name__ == "__main__":
    main()
