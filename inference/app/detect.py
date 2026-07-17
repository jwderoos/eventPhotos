from dataclasses import dataclass

import numpy as np
import onnxruntime as ort
from PIL import Image

from app.bib_config import (
    NMS_IOU,
    PERSON_SCORE_THR,
    YOLOX_INPUT_SIZE,
    YOLOX_WEIGHTS_PATH,
)

_PERSON_CLASS = 0
_PAD_VALUE = 114


@dataclass(frozen=True)
class Box:
    x1: float
    y1: float
    x2: float
    y2: float
    score: float


def _letterbox(image: Image.Image, input_hw: tuple[int, int]) -> tuple[np.ndarray, float]:
    """Resize preserving aspect ratio, pad to input_hw with 114. Returns
    (CHW float32 BGR array, ratio). No normalization (YOLOX preproc)."""
    ih, iw = input_hw
    rgb = np.asarray(image.convert("RGB"))
    h, w = rgb.shape[:2]
    ratio = min(ih / h, iw / w)
    nh, nw = int(round(h * ratio)), int(round(w * ratio))
    resized = np.asarray(Image.fromarray(rgb).resize((nw, nh), Image.BILINEAR))
    canvas = np.full((ih, iw, 3), _PAD_VALUE, dtype=np.uint8)
    canvas[:nh, :nw] = resized
    bgr = canvas[:, :, ::-1]                      # RGB -> BGR
    chw = bgr.transpose(2, 0, 1).astype(np.float32)
    return chw[np.newaxis, ...], ratio


def decode_yolox(output: np.ndarray, input_hw: tuple[int, int]) -> np.ndarray:
    """Raw [1,N,85] -> [N,6] rows [x1,y1,x2,y2,score,cls] in input-pixel space."""
    preds = output[0]
    ih, iw = input_hw
    grids, strides = [], []
    for stride in (8, 16, 32):
        gh, gw = ih // stride, iw // stride
        xv, yv = np.meshgrid(np.arange(gw), np.arange(gh))
        grid = np.stack((xv, yv), axis=-1).reshape(-1, 2)
        grids.append(grid)
        strides.append(np.full((grid.shape[0], 1), stride))
    grid = np.concatenate(grids, axis=0)
    stride = np.concatenate(strides, axis=0)

    # Canonical YOLOX decode: grid offset + predicted center offset, scaled by stride.
    xy = (preds[:, :2] + grid) * stride
    wh = np.exp(preds[:, 2:4]) * stride
    obj = preds[:, 4:5]
    cls_scores = preds[:, 5:]
    cls_id = cls_scores.argmax(axis=1)
    cls_conf = cls_scores.max(axis=1)
    score = (obj[:, 0] * cls_conf)

    x1 = xy[:, 0] - wh[:, 0] / 2
    y1 = xy[:, 1] - wh[:, 1] / 2
    x2 = xy[:, 0] + wh[:, 0] / 2
    y2 = xy[:, 1] + wh[:, 1] / 2
    return np.stack([x1, y1, x2, y2, score, cls_id.astype(np.float32)], axis=1)


def nms(boxes: np.ndarray, scores: np.ndarray, iou_thr: float) -> list[int]:
    x1, y1, x2, y2 = boxes[:, 0], boxes[:, 1], boxes[:, 2], boxes[:, 3]
    areas = (x2 - x1) * (y2 - y1)
    order = scores.argsort()[::-1]
    keep: list[int] = []
    while order.size > 0:
        i = int(order[0])
        keep.append(i)
        xx1 = np.maximum(x1[i], x1[order[1:]])
        yy1 = np.maximum(y1[i], y1[order[1:]])
        xx2 = np.minimum(x2[i], x2[order[1:]])
        yy2 = np.minimum(y2[i], y2[order[1:]])
        w = np.maximum(0.0, xx2 - xx1)
        h = np.maximum(0.0, yy2 - yy1)
        inter = w * h
        iou = inter / (areas[i] + areas[order[1:]] - inter)
        order = order[1:][iou <= iou_thr]
    return keep


class PersonDetector:
    def __init__(self, weights_path=YOLOX_WEIGHTS_PATH):
        self._session = ort.InferenceSession(
            str(weights_path), providers=["CPUExecutionProvider"]
        )
        self._input_name = self._session.get_inputs()[0].name

    def detect(self, image: Image.Image) -> list[Box]:
        blob, ratio = _letterbox(image, YOLOX_INPUT_SIZE)
        output = self._session.run(None, {self._input_name: blob})[0]
        rows = decode_yolox(output, YOLOX_INPUT_SIZE)
        persons = rows[(rows[:, 5] == _PERSON_CLASS) & (rows[:, 4] >= PERSON_SCORE_THR)]
        if persons.shape[0] == 0:
            return []
        keep = nms(persons[:, :4], persons[:, 4], NMS_IOU)
        boxes = []
        for i in keep:
            x1, y1, x2, y2, score = persons[i, :5]
            boxes.append(Box(x1 / ratio, y1 / ratio, x2 / ratio, y2 / ratio, float(score)))
        return boxes
