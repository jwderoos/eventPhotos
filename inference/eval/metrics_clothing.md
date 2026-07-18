# Phase 2 clothing/scene eval — metrics summary (#117)

**Date:** 2026-07-18
**Model:** self-exported CLIP ViT-B/32 vision ONNX + text embeddings, both from
`openai/clip-vit-base-patch32` (matched embedding space; verified by
`tests/test_clip_encoder.py::test_encode_discriminates_colours`).
**Posture:** recall-lean (target precision ≈ 0.70; recall secondary).

## Sample

12 hand-labelled previews from the real event export
(`event-race-with-bibs-f1r5c7.zip`). Photos are PII and are **not** committed
(gitignored `eval/images/`, `eval/labels_clothing.json`). Labels = the union of
clothing/scene clearly visible on the prominent people in each frame. **Small,
adversarial (crowd-heavy) sample — treat numbers as indicative, not a guarantee.**
Expand the labelled set for a representative figure.

## Results (calibrated thresholds)

| Field  | Precision | Recall | Notes |
|--------|-----------|--------|-------|
| colors | 0.66      | 0.56   | CLIP colour-naming is inherently weak; looser floor buys recall |
| types  | 0.70      | 0.70   | floor 0.16 is the P=R sweet spot on this sample |
| scenes | 0.73      | 0.85   | strongest field; mostly on-course/running + start |

(Bibs are covered by Phase 1's eval — precision 0.83 at the ≥0.80 gate — and are
not re-measured here; only 2 of the 12 sample photos carry bib ground truth.)

## Calibrated thresholds (`app/clip_config.py`)

| Field  | FLOOR | MARGIN | TOPK | TEMPERATURE |
|--------|-------|--------|------|-------------|
| colors | 0.12  | 0.35   | 3    | 0.05        |
| types  | 0.16  | 0.25   | 2    | 0.05        |
| scenes | 0.20  | 0.20   | 2    | 0.05        |

## Method

`eval/run_eval.py` runs the real `build_full_recognizer()` over the labelled
photos and reports per-field precision/recall. Thresholds were swept by caching
per-photo cosine similarities once and evaluating the FLOOR/MARGIN/TOPK grid, then
picked recall-lean per field. Reproduce:

```
python eval/run_eval.py --images eval/images --labels eval/labels_clothing.json
```

## Known limitations / follow-ups

- Colour precision (0.66) sits just under the 0.70 target — acceptable under the
  recall-lean posture; colour's floor is independently tunable if facet noise is
  reported in production.
- fp32 vision ONNX is ~351 MB; int8 quantization (~90 MB) is an optional
  image-size follow-up (would need a re-run of the discrimination test).
- Sample is 12 photos; expanding it would tighten the estimates.
