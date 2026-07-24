import numpy as np
from PIL import Image

from app.bib_parser import TextLine


class TextReader:
    """Wraps RapidOCR (PP-OCRv4 ONNX, bundled in the wheel). In-memory only."""

    def __init__(self):
        from rapidocr_onnxruntime import RapidOCR

        self._engine = RapidOCR()

    def read(self, image: Image.Image) -> list[TextLine]:
        bgr = np.asarray(image.convert("RGB"))[:, :, ::-1]
        result, _ = self._engine(bgr)
        if not result:
            return []
        lines: list[TextLine] = []
        for box, text, score in result:
            ys = [pt[1] for pt in box]
            height_px = float(max(ys) - min(ys))
            lines.append(TextLine(text=str(text), confidence=float(score), height_px=height_px))
        return lines
