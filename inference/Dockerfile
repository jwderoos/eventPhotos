FROM python:3.12-slim

# curl for the build-time weight fetch; libgl/glib for onnxruntime + image libs.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl libgl1 libglib2.0-0 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /srv
COPY pyproject.toml ./
# Runtime deps only — the `test` extra (pytest/httpx) is dev/CI tooling.
RUN pip install --no-cache-dir "."
COPY app ./app
COPY scripts ./scripts

# Bake the YOLOX-tiny ONNX (Apache-2.0) into the image. RapidOCR models already
# ship inside the pip wheel, so no separate OCR download is needed.
RUN sh scripts/fetch_weights.sh

EXPOSE 8000
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
