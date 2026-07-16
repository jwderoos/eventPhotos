import io

from fastapi import FastAPI, HTTPException, Request
from PIL import Image, UnidentifiedImageError

from app.recognizer import RawResult, Score, StubRecognizer
from app.schemas import ExtractResponse, ScoreOut
from app.vocabulary import VOCABULARY

app = FastAPI(title="EventPhotos Inference", version="0.1.0")

# Swap for a production Recognizer implementation (see recognizer.py plug points).
RECOGNIZER = StubRecognizer()

_UNPROCESSABLE = 422


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


def _in_vocab(scores: list[Score], allowed: set[str]) -> list[ScoreOut]:
    return [ScoreOut(value=s.value, confidence=s.confidence) for s in scores if s.value in allowed]


@app.post("/extract", response_model=ExtractResponse)
async def extract(request: Request) -> ExtractResponse:
    body = await request.body()
    try:
        # Processed entirely in memory; the image is never written to disk.
        image = Image.open(io.BytesIO(body))
        image.load()
    except (UnidentifiedImageError, OSError) as exc:
        raise HTTPException(status_code=_UNPROCESSABLE, detail="unreadable image") from exc

    result: RawResult = RECOGNIZER.recognize(image)
    return ExtractResponse(
        clothing_colors=_in_vocab(result.clothing_colors, VOCABULARY["clothing_colors"]),
        clothing_types=_in_vocab(result.clothing_types, VOCABULARY["clothing_types"]),
        scenes=_in_vocab(result.scenes, VOCABULARY["scenes"]),
        # Bibs are free-form alphanumeric (OCR); the PHP gate applies the confidence
        # threshold, per-event toggle, and suppress-list.
        bibs=[ScoreOut(value=b.value, confidence=b.confidence) for b in result.bibs],
    )
