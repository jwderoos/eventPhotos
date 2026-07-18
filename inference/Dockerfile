# --- builder: one-time CLIP vision ONNX self-export (heavy deps stay here) ---
FROM python:3.12-slim AS builder

# curl for the YOLOX fetch; git occasionally needed by HF downloads.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl git \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /srv
COPY app ./app
COPY scripts ./scripts

# Builder-only heavy deps for the ONNX export — NOT installed in the runtime image.
RUN pip install --no-cache-dir torch --index-url https://download.pytorch.org/whl/cpu \
    && pip install --no-cache-dir "transformers>=4.40" onnxscript

# Self-export the CLIP vision tower from openai/clip-vit-base-patch32 (matched to
# the committed text embeddings), then fetch YOLOX. Both land in app/weights/.
RUN python scripts/export_vision_onnx.py \
    && sh scripts/fetch_weights.sh

# --- runtime: slim, no torch ---
FROM python:3.12-slim

# libgl/glib for onnxruntime + image libs.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libgl1 libglib2.0-0 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /srv
COPY pyproject.toml ./
# Runtime deps only — the `test` extra (pytest/httpx) is dev/CI tooling.
RUN pip install --no-cache-dir "."
COPY app ./app
COPY scripts ./scripts
# Bring the baked weights (CLIP + YOLOX) from the builder. The committed text
# embeddings under app/text_embeddings/ arrive via `COPY app`.
COPY --from=builder /srv/app/weights/ ./app/weights/

EXPOSE 8000
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
