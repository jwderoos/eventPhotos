"""Offline eval: run the real full recognizer over labelled photos, report
per-field precision/recall. Real photos are PII and must not be committed.

Labels schema: {filename: {colors:[...], types:[...], scenes:[...], bibs:[...]}}."""

import argparse
import json
from pathlib import Path

from PIL import Image

from app.bib_recognizer import build_full_recognizer

_FIELDS = ("colors", "types", "scenes", "bibs")


def _predicted(result, field, min_bib_conf):
    if field == "colors":
        return {s.value for s in result.clothing_colors}
    if field == "types":
        return {s.value for s in result.clothing_types}
    if field == "scenes":
        return {s.value for s in result.scenes}
    return {s.value for s in result.bibs if s.confidence >= min_bib_conf}


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--images", required=True, type=Path)
    ap.add_argument("--labels", required=True, type=Path)
    ap.add_argument("--min-bib-conf", type=float, default=0.80)
    args = ap.parse_args()

    labels = json.loads(args.labels.read_text())
    recognizer = build_full_recognizer()

    agg = {f: {"tp": 0, "fp": 0, "fn": 0, "fps": []} for f in _FIELDS}
    for filename, expected in labels.items():
        img = Image.open(args.images / filename)
        img.load()
        result = recognizer.recognize(img)
        for field in _FIELDS:
            predicted = _predicted(result, field, args.min_bib_conf)
            exp = set(expected.get(field, []))
            for value in predicted:
                if value in exp:
                    agg[field]["tp"] += 1
                else:
                    agg[field]["fp"] += 1
                    agg[field]["fps"].append((filename, value))
            agg[field]["fn"] += len(exp - predicted)

    for field in _FIELDS:
        a = agg[field]
        p = a["tp"] / (a["tp"] + a["fp"]) if (a["tp"] + a["fp"]) else 0.0
        r = a["tp"] / (a["tp"] + a["fn"]) if (a["tp"] + a["fn"]) else 0.0
        print(f"[{field}] TP={a['tp']} FP={a['fp']} FN={a['fn']} "
              f"precision={p:.3f} recall={r:.3f}")
        for filename, value in a["fps"]:
            print(f"    FP {filename}: {value!r}")


if __name__ == "__main__":
    main()
