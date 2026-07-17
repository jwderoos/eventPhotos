"""Offline eval: run the real bib pipeline over labelled photos, report
precision/recall. Real photos are PII and must not be committed."""

import argparse
import json
from pathlib import Path

from PIL import Image

from app.bib_recognizer import build_bib_recognizer


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--images", required=True, type=Path)
    ap.add_argument("--labels", required=True, type=Path)
    ap.add_argument("--min-conf", type=float, default=0.80,
                    help="mirror the PHP bib gate (default 0.80)")
    args = ap.parse_args()

    labels = json.loads(args.labels.read_text())
    recognizer = build_bib_recognizer()

    tp = fp = fn = 0
    false_positives: list[tuple[str, str]] = []
    for filename, expected in labels.items():
        img = Image.open(args.images / filename)
        img.load()
        scores = recognizer.recognize(img).bibs
        predicted = {s.value for s in scores if s.confidence >= args.min_conf}
        expected_set = set(expected)
        for value in predicted:
            if value in expected_set:
                tp += 1
            else:
                fp += 1
                false_positives.append((filename, value))
        fn += len(expected_set - predicted)
        print(f"{filename}: predicted={sorted(predicted)} expected={sorted(expected_set)}")

    precision = tp / (tp + fp) if (tp + fp) else 0.0
    recall = tp / (tp + fn) if (tp + fn) else 0.0
    print(f"\nTP={tp} FP={fp} FN={fn}")
    print(f"precision={precision:.3f} recall={recall:.3f}")
    if false_positives:
        print("\nFALSE POSITIVES (must be ~zero for precision-first acceptance):")
        for filename, value in false_positives:
            print(f"  {filename}: {value!r}")


if __name__ == "__main__":
    main()
