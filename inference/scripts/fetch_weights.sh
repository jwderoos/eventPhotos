#!/usr/bin/env sh
# Downloads the YOLOX-tiny ONNX person detector (Apache-2.0) into app/weights/.
# Immutable GitHub release asset. Verified size guards against a bad/partial pull.
set -eu

DEST="$(dirname "$0")/../app/weights/yolox_tiny.onnx"
URL="https://github.com/Megvii-BaseDetection/YOLOX/releases/download/0.1.1rc0/yolox_tiny.onnx"
EXPECTED_BYTES="20219662"
EXPECTED_SHA256="427cc366d34e27ff7a03e2899b5e3671425c262ea2291f88bb942bc1cc70b0f7"

mkdir -p "$(dirname "$DEST")"
# wc -c pads its output with leading spaces on BSD/macOS; trim before comparing.
if [ -f "$DEST" ] && [ "$(wc -c < "$DEST" | tr -d '[:space:]')" = "$EXPECTED_BYTES" ]; then
    echo "yolox_tiny.onnx already present and correct size."
else
    echo "Downloading yolox_tiny.onnx ..."
    curl -fSL "$URL" -o "$DEST"
fi

ACTUAL_BYTES="$(wc -c < "$DEST" | tr -d '[:space:]')"
if [ "$ACTUAL_BYTES" != "$EXPECTED_BYTES" ]; then
    echo "SIZE MISMATCH: expected $EXPECTED_BYTES got $ACTUAL_BYTES" >&2
    exit 1
fi
if command -v sha256sum >/dev/null 2>&1; then
    ACTUAL_SHA256="$(sha256sum "$DEST" | awk '{print $1}')"
else
    ACTUAL_SHA256="$(shasum -a 256 "$DEST" | awk '{print $1}')"
fi
if [ "$ACTUAL_SHA256" != "$EXPECTED_SHA256" ]; then
    echo "SHA256 MISMATCH: expected $EXPECTED_SHA256 got $ACTUAL_SHA256" >&2
    exit 1
fi
echo "sha256 OK: $ACTUAL_SHA256"
