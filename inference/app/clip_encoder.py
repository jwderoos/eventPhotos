import numpy as np
import onnxruntime as ort
from PIL import Image

from app.clip_config import CLIP_INPUT_SIZE, CLIP_MEAN, CLIP_STD, CLIP_WEIGHTS_PATH


class ClipImageEncoder:
    """CLIP ViT-B/32 vision tower (ONNX, CPU). encode() is in-memory only."""

    def __init__(self, weights_path=CLIP_WEIGHTS_PATH):
        self._session = ort.InferenceSession(
            str(weights_path), providers=["CPUExecutionProvider"]
        )
        self._input_name = self._session.get_inputs()[0].name
        self._mean = np.asarray(CLIP_MEAN, dtype=np.float32).reshape(3, 1, 1)
        self._std = np.asarray(CLIP_STD, dtype=np.float32).reshape(3, 1, 1)

    def _preprocess(self, image: Image.Image) -> np.ndarray:
        rgb = image.convert("RGB")
        w, h = rgb.size
        scale = CLIP_INPUT_SIZE / min(w, h)
        rgb = rgb.resize((round(w * scale), round(h * scale)), Image.BICUBIC)
        w, h = rgb.size
        left = (w - CLIP_INPUT_SIZE) // 2
        top = (h - CLIP_INPUT_SIZE) // 2
        rgb = rgb.crop((left, top, left + CLIP_INPUT_SIZE, top + CLIP_INPUT_SIZE))
        arr = np.asarray(rgb, dtype=np.float32) / 255.0        # HWC [0,1]
        chw = arr.transpose(2, 0, 1)
        chw = (chw - self._mean) / self._std
        return chw[np.newaxis, ...].astype(np.float32)

    def encode(self, image: Image.Image) -> np.ndarray:
        blob = self._preprocess(image)
        out = self._session.run(None, {self._input_name: blob})[0]
        vec = np.asarray(out, dtype=np.float32).reshape(-1)
        return vec / np.linalg.norm(vec)
