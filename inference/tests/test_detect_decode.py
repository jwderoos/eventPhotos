import numpy as np

from app.detect import decode_yolox, nms


def test_nms_suppresses_overlapping_boxes():
    boxes = np.array([[0, 0, 10, 10], [1, 1, 11, 11], [100, 100, 110, 110]], dtype=float)
    scores = np.array([0.9, 0.8, 0.7])
    keep = nms(boxes, scores, iou_thr=0.45)
    assert 0 in keep and 2 in keep and 1 not in keep


def test_decode_shapes_and_center_to_corner():
    # One 1x1 grid at stride 8 on a 8x8 input: single prediction.
    # Raw row: cx_off=0, cy_off=0, w=log-space 0 -> exp(0)*8=8, h=8, obj=1, cls0=1.
    raw = np.zeros((1, 1, 85), dtype=np.float32)
    raw[0, 0, 4] = 1.0   # obj_conf
    raw[0, 0, 5] = 1.0   # class 0 (person)
    out = decode_yolox(raw, input_hw=(8, 8))
    assert out.shape == (1, 6)
    x1, y1, x2, y2, score, cls = out[0]
    # Canonical: center (0,0), w=h=8 -> corners (-4,-4,4,4)
    assert abs(x1 - (-4)) < 1e-4 and abs(x2 - 4) < 1e-4 and abs(y1 - (-4)) < 1e-4 and abs(y2 - 4) < 1e-4
    assert abs(score - 1.0) < 1e-4 and int(cls) == 0
