# Plan B — CPU Inference Microservice + Symfony Client (#109) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up a self-hosted CPU inference service that turns a preview-derivative JPEG into controlled-vocabulary attribute tags, and a Symfony client that calls it and re-validates the result against the allowlist.

**Architecture:** A standalone FastAPI service (`inference/`) exposes `POST /extract` and `GET /health`. A `Recognizer` seam isolates the model internals — v1 ships a deterministic `StubRecognizer` (no multi-GB weights) with clearly-marked plug points for CLIP/FashionCLIP (clothing/scene) and YOLO+PaddleOCR (bibs). All output is filtered to the fixed vocabularies. On the Symfony side, `AttributeExtractorClient` (behind `AttributeExtractorClientInterface`) POSTs image bytes via a scoped HTTP client and maps the JSON into an `ExtractedAttributes` value object, dropping any value not in the allowlist. A `FakeAttributeExtractorClient` is bound under `when@test`.

**Tech Stack:** Python 3.12 / FastAPI / Uvicorn / Pillow / pytest (service); PHP 8.5 / Symfony 8 `HttpClientInterface` (client). Docker Compose for runtime.

## Global Constraints

- PHP 8.5 / Symfony 8. Python 3.12, FastAPI.
- Quality gates (GrumPHP + CI): `phpstan` level 10; `phpcs` PSR-12; `phpmnd` (no magic numbers in `src/`); `rector`; `securitychecker_roave`; `doctrine:schema:validate`. Python service tested with `pytest`.
- Branch name must match `^(feature|hotfix|bugfix|release)/\d+-` (use `feature/109-inference-service`). `main`/`develop`/`master` blacklisted for direct commits. Commit messages must contain issue number `109`.
- Tests fail on any deprecation/notice/warning (`failOnDeprecation`/`failOnNotice`/`failOnWarning`).
- **Boundary constraints (from `docs/superpowers/specs/2026-07-15-109-attribute-tagging-build-design.md`):** NO face/biometric code paths; NO photo retention (process in-memory, never write the image to disk); every returned `value` MUST be in the fixed vocabulary.
- **This repo does not auto-commit** (CLAUDE.md: "Claude will not do commits"). The "Commit" steps below are commands for the human operator to run; the implementing agent must NOT execute git.

## Interfaces (shared contract — Plan C consumes these; do NOT rename)

```php
// src/Service/Photo/AttributeScore.php
final readonly class AttributeScore {
    public function __construct(public string $value, public float $confidence) {}
}

// src/Service/Photo/ExtractedAttributes.php
final readonly class ExtractedAttributes {
    /** @param list<AttributeScore> $clothingColors @param list<AttributeScore> $clothingTypes
     *  @param list<AttributeScore> $scenes         @param list<AttributeScore> $bibs */
    public function __construct(
        public array $clothingColors, public array $clothingTypes,
        public array $scenes, public array $bibs,
    ) {}
    public static function empty(): self { return new self([], [], [], []); }
}

// src/Service/Photo/AttributeExtractorClientInterface.php
interface AttributeExtractorClientInterface {
    public function extract(string $imageBytes): ExtractedAttributes;
}
```

HTTP contract (service ↔ client):
`POST /extract` body = raw JPEG bytes (`Content-Type: image/jpeg`) →
`{"clothing_colors":[{"value","confidence"}],"clothing_types":[...],"scenes":[...],"bibs":[...]}`.
`GET /health` → `{"status":"ok"}`.

---

### Task 1: Python service scaffold + `/health`

**Files:**
- Create: `inference/pyproject.toml`
- Create: `inference/app/__init__.py`
- Create: `inference/app/main.py`
- Test: `inference/tests/test_health.py`

**Interfaces:**
- Produces: FastAPI `app` importable as `app.main:app`; `GET /health` → `{"status":"ok"}`.

- [ ] **Step 1: Write the failing test**

`inference/tests/test_health.py`:
```python
from fastapi.testclient import TestClient
from app.main import app

client = TestClient(app)


def test_health_returns_ok():
    resp = client.get("/health")
    assert resp.status_code == 200
    assert resp.json() == {"status": "ok"}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd inference && python -m pytest tests/test_health.py -v`
Expected: FAIL — `ModuleNotFoundError: No module named 'app.main'`.

- [ ] **Step 3: Write minimal implementation**

`inference/pyproject.toml`:
```toml
[project]
name = "eventphotos-inference"
version = "0.1.0"
requires-python = ">=3.12"
dependencies = [
    "fastapi>=0.115",
    "uvicorn[standard]>=0.30",
    "pillow>=10.4",
    "pydantic>=2.8",
]

[project.optional-dependencies]
test = ["pytest>=8.2", "httpx>=0.27"]

[tool.pytest.ini_options]
pythonpath = ["."]
```

`inference/app/__init__.py`:
```python
```

`inference/app/main.py`:
```python
from fastapi import FastAPI

app = FastAPI(title="EventPhotos Inference", version="0.1.0")


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd inference && pip install -e ".[test]" && python -m pytest tests/test_health.py -v`
Expected: PASS (1 passed).

- [ ] **Step 5: Commit (human runs)**

```bash
git add inference/pyproject.toml inference/app tests
git commit -m "109 - inference service scaffold + health endpoint"
```

---

### Task 2: Fixed vocabulary + `Recognizer` seam + `StubRecognizer`

**Files:**
- Create: `inference/app/vocabulary.py`
- Create: `inference/app/recognizer.py`
- Test: `inference/tests/test_recognizer.py`

**Interfaces:**
- Produces: `VOCABULARY: dict[str, set[str]]` keyed `clothing_colors|clothing_types|scenes`; `Recognizer` protocol with `recognize(image: PIL.Image.Image) -> RawResult`; `StubRecognizer` (deterministic). Real models plug in by implementing `Recognizer`.

- [ ] **Step 1: Write the failing test**

`inference/tests/test_recognizer.py`:
```python
from PIL import Image
from app.recognizer import StubRecognizer
from app.vocabulary import VOCABULARY


def _img() -> Image.Image:
    return Image.new("RGB", (64, 64), (255, 128, 0))


def test_stub_returns_only_vocab_values():
    result = StubRecognizer().recognize(_img())
    for term in [s.value for s in result.clothing_colors]:
        assert term in VOCABULARY["clothing_colors"]
    for term in [s.value for s in result.clothing_types]:
        assert term in VOCABULARY["clothing_types"]
    for term in [s.value for s in result.scenes]:
        assert term in VOCABULARY["scenes"]


def test_stub_bib_is_digit_string_with_confidence():
    result = StubRecognizer().recognize(_img())
    for bib in result.bibs:
        assert bib.value.isalnum()
        assert 0.0 <= bib.confidence <= 1.0
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd inference && python -m pytest tests/test_recognizer.py -v`
Expected: FAIL — `ModuleNotFoundError: No module named 'app.recognizer'`.

- [ ] **Step 3: Write minimal implementation**

`inference/app/vocabulary.py`:
```python
"""Fixed allowlist vocabularies. Adding a term is a spec change (see build spec)."""

VOCABULARY: dict[str, set[str]] = {
    "clothing_colors": {
        "black", "white", "grey", "red", "orange", "yellow",
        "green", "blue", "purple", "pink", "brown", "beige",
    },
    "clothing_types": {
        "t-shirt", "long-sleeve shirt", "jacket", "hoodie/sweater",
        "dress", "shorts", "trousers", "skirt", "hat/cap",
    },
    "scenes": {
        "start", "finish-line", "on-course/running",
        "water-station", "crowd/spectators", "medal/podium",
    },
}
```

`inference/app/recognizer.py`:
```python
from dataclasses import dataclass, field
from typing import Protocol

from PIL import Image

from app.vocabulary import VOCABULARY


@dataclass(frozen=True)
class Score:
    value: str
    confidence: float


@dataclass(frozen=True)
class RawResult:
    clothing_colors: list[Score] = field(default_factory=list)
    clothing_types: list[Score] = field(default_factory=list)
    scenes: list[Score] = field(default_factory=list)
    bibs: list[Score] = field(default_factory=list)


class Recognizer(Protocol):
    def recognize(self, image: Image.Image) -> RawResult: ...


# Nearest fixed-palette colour name for the image's average RGB.
_PALETTE: dict[str, tuple[int, int, int]] = {
    "black": (0, 0, 0), "white": (255, 255, 255), "grey": (128, 128, 128),
    "red": (220, 20, 20), "orange": (255, 128, 0), "yellow": (240, 220, 30),
    "green": (30, 160, 60), "blue": (30, 80, 220), "purple": (130, 40, 190),
    "pink": (240, 130, 190), "brown": (120, 70, 30), "beige": (220, 200, 160),
}


class StubRecognizer:
    """Deterministic, weight-free stand-in for v1 wiring and tests.

    PLUG POINTS for production models (each returns list[Score] in-vocabulary):
      * clothing colour/type + scene -> zero-shot CLIP / FashionCLIP over VOCABULARY
      * bibs -> YOLO bib-detector crop -> PaddleOCR digit recognition
    Swap StubRecognizer for the real implementation of the Recognizer protocol;
    main.py depends only on the protocol.
    """

    def recognize(self, image: Image.Image) -> RawResult:
        rgb = image.convert("RGB").resize((1, 1)).getpixel((0, 0))
        colour = min(
            _PALETTE,
            key=lambda name: sum((a - b) ** 2 for a, b in zip(_PALETTE[name], rgb)),
        )
        return RawResult(
            clothing_colors=[Score(colour, 0.90)],
            clothing_types=[Score("t-shirt", 0.85)],
            scenes=[Score("on-course/running", 0.70)],
            bibs=[],  # stub emits no bibs; real OCR fills this
        )
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd inference && python -m pytest tests/test_recognizer.py -v`
Expected: PASS (2 passed).

- [ ] **Step 5: Commit (human runs)**

```bash
git add inference/app/vocabulary.py inference/app/recognizer.py inference/tests/test_recognizer.py
git commit -m "109 - inference recognizer seam + fixed vocabulary + deterministic stub"
```

---

### Task 3: `/extract` endpoint with vocabulary enforcement

**Files:**
- Modify: `inference/app/main.py`
- Create: `inference/app/schemas.py`
- Test: `inference/tests/test_extract.py`

**Interfaces:**
- Consumes: `Recognizer`/`StubRecognizer`, `VOCABULARY` (Task 2).
- Produces: `POST /extract` (raw JPEG body) → JSON `{clothing_colors,clothing_types,scenes,bibs}` with every `value` in-vocabulary; unknown labels dropped; image never persisted.

- [ ] **Step 1: Write the failing test**

`inference/tests/test_extract.py`:
```python
import io

from fastapi.testclient import TestClient
from PIL import Image

from app.main import app
from app.vocabulary import VOCABULARY

client = TestClient(app)


def _jpeg_bytes(colour=(255, 128, 0)) -> bytes:
    buf = io.BytesIO()
    Image.new("RGB", (64, 64), colour).save(buf, format="JPEG")
    return buf.getvalue()


def test_extract_returns_full_schema():
    resp = client.post(
        "/extract", content=_jpeg_bytes(), headers={"Content-Type": "image/jpeg"}
    )
    assert resp.status_code == 200
    body = resp.json()
    assert set(body.keys()) == {"clothing_colors", "clothing_types", "scenes", "bibs"}


def test_extract_values_are_all_in_vocabulary():
    body = client.post(
        "/extract", content=_jpeg_bytes(), headers={"Content-Type": "image/jpeg"}
    ).json()
    for key in ("clothing_colors", "clothing_types", "scenes"):
        for item in body[key]:
            assert item["value"] in VOCABULARY[key]
            assert 0.0 <= item["confidence"] <= 1.0


def test_extract_rejects_non_image():
    resp = client.post(
        "/extract", content=b"not-an-image", headers={"Content-Type": "image/jpeg"}
    )
    assert resp.status_code == 422


def test_extract_drops_out_of_vocabulary_terms(monkeypatch):
    from app import main
    from app.recognizer import RawResult, Score

    class RogueRecognizer:
        def recognize(self, image):
            return RawResult(
                clothing_colors=[Score("orange", 0.9), Score("chartreuse", 0.9)],
            )

    monkeypatch.setattr(main, "RECOGNIZER", RogueRecognizer())
    body = client.post(
        "/extract", content=_jpeg_bytes(), headers={"Content-Type": "image/jpeg"}
    ).json()
    values = [c["value"] for c in body["clothing_colors"]]
    assert "orange" in values
    assert "chartreuse" not in values
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd inference && python -m pytest tests/test_extract.py -v`
Expected: FAIL — 404 on `/extract` (route not defined).

- [ ] **Step 3: Write minimal implementation**

`inference/app/schemas.py`:
```python
from pydantic import BaseModel


class ScoreOut(BaseModel):
    value: str
    confidence: float


class ExtractResponse(BaseModel):
    clothing_colors: list[ScoreOut] = []
    clothing_types: list[ScoreOut] = []
    scenes: list[ScoreOut] = []
    bibs: list[ScoreOut] = []
```

Replace `inference/app/main.py` with:
```python
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd inference && python -m pytest -v`
Expected: PASS (all tests across the three test files).

- [ ] **Step 5: Commit (human runs)**

```bash
git add inference/app/main.py inference/app/schemas.py inference/tests/test_extract.py
git commit -m "109 - inference /extract endpoint with vocabulary enforcement (in-memory, no retention)"
```

---

### Task 4: Dockerfile + Compose service + env

**Files:**
- Create: `inference/Dockerfile`
- Create: `inference/.dockerignore`
- Modify: `compose.yaml`
- Modify: `.env`

**Interfaces:**
- Produces: an `inference` compose service reachable at `http://inference:8000` on the default compose network; env var `INFERENCE_SERVICE_URL`.

- [ ] **Step 1: Write the Dockerfile**

`inference/Dockerfile`:
```dockerfile
FROM python:3.12-slim

WORKDIR /srv
COPY pyproject.toml ./
RUN pip install --no-cache-dir ".[test]"
COPY app ./app

EXPOSE 8000
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
```

`inference/.dockerignore`:
```
tests
__pycache__
*.pyc
.pytest_cache
```

- [ ] **Step 2: Add the compose service**

In `compose.yaml`, add under `services:` (mirrors the `worker` restart policy; CPU-only, no GPU reservation):
```yaml
  inference:
    build:
      context: ./inference
      dockerfile: Dockerfile
    restart: unless-stopped
    stop_signal: SIGTERM
    stop_grace_period: 15s
    healthcheck:
      test: ["CMD", "python", "-c", "import urllib.request; urllib.request.urlopen('http://localhost:8000/health')"]
      timeout: 5s
      retries: 5
      start_period: 30s
```

Add `INFERENCE_SERVICE_URL` to the `php` and `worker` service `environment:` blocks:
```yaml
      INFERENCE_SERVICE_URL: "http://inference:8000"
```
And make `worker` depend on it:
```yaml
    depends_on:
      database:
        condition: service_healthy
      inference:
        condition: service_started
```

- [ ] **Step 3: Add the dev default to `.env`**

Append to `.env`:
```dotenv
###> app/inference ###
INFERENCE_SERVICE_URL=http://inference:8000
###< app/inference ###
```

- [ ] **Step 4: Verify the image builds and serves**

Run: `docker compose build inference && docker compose up -d inference && sleep 5 && docker compose exec -T inference python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://localhost:8000/health').read()==b'{\"status\":\"ok\"}' else 1)" && echo OK`
Expected: `OK`.

- [ ] **Step 5: Commit (human runs)**

```bash
git add inference/Dockerfile inference/.dockerignore compose.yaml .env
git commit -m "109 - inference service Dockerfile + compose entry + INFERENCE_SERVICE_URL"
```

---

### Task 5: PHP value objects + allowlist vocabulary

**Files:**
- Create: `src/Service/Photo/AttributeScore.php`
- Create: `src/Service/Photo/ExtractedAttributes.php`
- Create: `src/Service/Photo/AttributeVocabulary.php`
- Test: `tests/Unit/Service/Photo/AttributeVocabularyTest.php`

**Interfaces:**
- Produces: `AttributeScore`, `ExtractedAttributes` (shared contract above), and `AttributeVocabulary::COLORS|GARMENTS|SCENES` (`list<string>`) + `isColor()/isGarment()/isScene()` used to drop unknown values PHP-side. These MUST mirror `inference/app/vocabulary.py`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Service/Photo/AttributeVocabularyTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Photo;

use App\Service\Photo\AttributeVocabulary;
use PHPUnit\Framework\TestCase;

final class AttributeVocabularyTest extends TestCase
{
    public function testKnownColorAccepted(): void
    {
        self::assertTrue(AttributeVocabulary::isColor('orange'));
    }

    public function testUnknownColorRejected(): void
    {
        self::assertFalse(AttributeVocabulary::isColor('chartreuse'));
    }

    public function testGarmentAndSceneMembership(): void
    {
        self::assertTrue(AttributeVocabulary::isGarment('t-shirt'));
        self::assertTrue(AttributeVocabulary::isScene('finish-line'));
        self::assertFalse(AttributeVocabulary::isScene('bedroom'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/Photo/AttributeVocabularyTest.php`
Expected: FAIL — class `App\Service\Photo\AttributeVocabulary` not found.

- [ ] **Step 3: Write minimal implementation**

`src/Service/Photo/AttributeScore.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Photo;

final readonly class AttributeScore
{
    public function __construct(
        public string $value,
        public float $confidence,
    ) {
    }
}
```

`src/Service/Photo/ExtractedAttributes.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Photo;

final readonly class ExtractedAttributes
{
    /**
     * @param list<AttributeScore> $clothingColors
     * @param list<AttributeScore> $clothingTypes
     * @param list<AttributeScore> $scenes
     * @param list<AttributeScore> $bibs
     */
    public function __construct(
        public array $clothingColors,
        public array $clothingTypes,
        public array $scenes,
        public array $bibs,
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], [], []);
    }
}
```

`src/Service/Photo/AttributeVocabulary.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Photo;

/**
 * PHP mirror of inference/app/vocabulary.py — the allowlist source of truth.
 * Adding a term here requires the same change in the Python service.
 */
final class AttributeVocabulary
{
    /** @var list<string> */
    public const array COLORS = [
        'black', 'white', 'grey', 'red', 'orange', 'yellow',
        'green', 'blue', 'purple', 'pink', 'brown', 'beige',
    ];

    /** @var list<string> */
    public const array GARMENTS = [
        't-shirt', 'long-sleeve shirt', 'jacket', 'hoodie/sweater',
        'dress', 'shorts', 'trousers', 'skirt', 'hat/cap',
    ];

    /** @var list<string> */
    public const array SCENES = [
        'start', 'finish-line', 'on-course/running',
        'water-station', 'crowd/spectators', 'medal/podium',
    ];

    public static function isColor(string $value): bool
    {
        return in_array($value, self::COLORS, true);
    }

    public static function isGarment(string $value): bool
    {
        return in_array($value, self::GARMENTS, true);
    }

    public static function isScene(string $value): bool
    {
        return in_array($value, self::SCENES, true);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Service/Photo/AttributeVocabularyTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit (human runs)**

```bash
git add src/Service/Photo/AttributeScore.php src/Service/Photo/ExtractedAttributes.php src/Service/Photo/AttributeVocabulary.php tests/Unit/Service/Photo/AttributeVocabularyTest.php
git commit -m "109 - PHP attribute value objects + allowlist vocabulary mirror"
```

---

### Task 6: `AttributeExtractorClient` (HTTP) + scoped client config

**Files:**
- Create: `src/Service/Photo/AttributeExtractorClientInterface.php`
- Create: `src/Service/Photo/AttributeExtractorClient.php`
- Create: `config/packages/http_client.yaml`
- Test: `tests/Unit/Service/Photo/AttributeExtractorClientTest.php`

**Interfaces:**
- Consumes: scoped client `inference.client` (autowired as `$inferenceClient`); `ExtractedAttributes`, `AttributeScore`, `AttributeVocabulary` (Task 5).
- Produces: `AttributeExtractorClientInterface::extract(string $imageBytes): ExtractedAttributes` — the seam Plan C's handler depends on. Unknown clothing/scene values are dropped; bibs pass through (gate is downstream).

- [ ] **Step 1: Write the failing test** (uses `MockHttpClient`, no network)

`tests/Unit/Service/Photo/AttributeExtractorClientTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Photo;

use App\Service\Photo\AttributeExtractorClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AttributeExtractorClientTest extends TestCase
{
    public function testMapsResponseAndDropsUnknownVocabulary(): void
    {
        $json = json_encode([
            'clothing_colors' => [
                ['value' => 'orange', 'confidence' => 0.9],
                ['value' => 'chartreuse', 'confidence' => 0.9],
            ],
            'clothing_types' => [['value' => 't-shirt', 'confidence' => 0.8]],
            'scenes' => [['value' => 'finish-line', 'confidence' => 0.7]],
            'bibs' => [['value' => '1423', 'confidence' => 0.95]],
        ], JSON_THROW_ON_ERROR);

        $http = new MockHttpClient(new MockResponse($json, [
            'response_headers' => ['content-type' => 'application/json'],
        ]));
        $client = new AttributeExtractorClient($http);

        $result = $client->extract('fake-jpeg-bytes');

        self::assertSame(['orange'], array_map(fn ($s) => $s->value, $result->clothingColors));
        self::assertSame('t-shirt', $result->clothingTypes[0]->value);
        self::assertSame('finish-line', $result->scenes[0]->value);
        self::assertSame('1423', $result->bibs[0]->value);
        self::assertEqualsWithDelta(0.95, $result->bibs[0]->confidence, 0.0001);
    }

    public function testReturnsEmptyOnServerError(): void
    {
        $http = new MockHttpClient(new MockResponse('boom', ['http_code' => 500]));
        $client = new AttributeExtractorClient($http);

        $result = $client->extract('bytes');

        self::assertSame([], $result->clothingColors);
        self::assertSame([], $result->bibs);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/Photo/AttributeExtractorClientTest.php`
Expected: FAIL — class `App\Service\Photo\AttributeExtractorClient` not found.

- [ ] **Step 3: Write minimal implementation**

`src/Service/Photo/AttributeExtractorClientInterface.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Photo;

interface AttributeExtractorClientInterface
{
    public function extract(string $imageBytes): ExtractedAttributes;
}
```

`src/Service/Photo/AttributeExtractorClient.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Photo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class AttributeExtractorClient implements AttributeExtractorClientInterface
{
    private const int HTTP_OK = 200;

    public function __construct(
        #[Autowire(service: 'inference.client')]
        private HttpClientInterface $inferenceClient,
    ) {
    }

    public function extract(string $imageBytes): ExtractedAttributes
    {
        try {
            $response = $this->inferenceClient->request('POST', 'extract', [
                'headers' => ['Content-Type' => 'image/jpeg'],
                'body' => $imageBytes,
            ]);

            if ($response->getStatusCode() !== self::HTTP_OK) {
                return ExtractedAttributes::empty();
            }

            /** @var array{clothing_colors?: list<array{value:string,confidence:float}>, clothing_types?: list<array{value:string,confidence:float}>, scenes?: list<array{value:string,confidence:float}>, bibs?: list<array{value:string,confidence:float}>} $data */
            $data = $response->toArray();
        } catch (ExceptionInterface) {
            return ExtractedAttributes::empty();
        }

        return new ExtractedAttributes(
            $this->scores($data['clothing_colors'] ?? [], AttributeVocabulary::isColor(...)),
            $this->scores($data['clothing_types'] ?? [], AttributeVocabulary::isGarment(...)),
            $this->scores($data['scenes'] ?? [], AttributeVocabulary::isScene(...)),
            $this->scores($data['bibs'] ?? [], static fn (string $v): bool => $v !== ''),
        );
    }

    /**
     * @param list<array{value:string,confidence:float}> $items
     * @param callable(string):bool $accept
     * @return list<AttributeScore>
     */
    private function scores(array $items, callable $accept): array
    {
        $out = [];
        foreach ($items as $item) {
            if ($accept($item['value'])) {
                $out[] = new AttributeScore($item['value'], $item['confidence']);
            }
        }

        return $out;
    }
}
```

`config/packages/http_client.yaml`:
```yaml
framework:
    http_client:
        scoped_clients:
            inference.client:
                base_uri: '%env(INFERENCE_SERVICE_URL)%'
                timeout: 10
                max_duration: 60
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Service/Photo/AttributeExtractorClientTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Alias the interface to the real client**

In `config/services.yaml`, under the top-level `services:` block (after the existing aliases ~line 29), add:
```yaml
    App\Service\Photo\AttributeExtractorClientInterface:
        alias: App\Service\Photo\AttributeExtractorClient
```

- [ ] **Step 6: Verify wiring + gates**

Run: `bin/console lint:container && vendor/bin/phpstan analyse src/Service/Photo`
Expected: container lints clean; PHPStan level 10 reports no errors.

- [ ] **Step 7: Commit (human runs)**

```bash
git add src/Service/Photo/AttributeExtractorClientInterface.php src/Service/Photo/AttributeExtractorClient.php config/packages/http_client.yaml config/services.yaml tests/Unit/Service/Photo/AttributeExtractorClientTest.php
git commit -m "109 - AttributeExtractorClient over scoped HTTP client + vocab re-validation"
```

---

### Task 7: `FakeAttributeExtractorClient` bound under `when@test`

**Files:**
- Create: `tests/Fake/FakeAttributeExtractorClient.php`
- Modify: `config/services.yaml`
- Test: `tests/Unit/Service/Photo/FakeAttributeExtractorClientTest.php`

**Interfaces:**
- Consumes: `AttributeExtractorClientInterface`, `ExtractedAttributes` (Tasks 5–6).
- Produces: `FakeAttributeExtractorClient` with `setNext(ExtractedAttributes): void` and `public string $lastImageBytes`; aliased for `AttributeExtractorClientInterface` in `when@test` (mirrors `FakeGoogleOAuthClient`). Plan C's integration tests use this to drive the handler without network or models.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Service/Photo/FakeAttributeExtractorClientTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Photo;

use App\Service\Photo\AttributeScore;
use App\Service\Photo\ExtractedAttributes;
use App\Tests\Fake\FakeAttributeExtractorClient;
use PHPUnit\Framework\TestCase;

final class FakeAttributeExtractorClientTest extends TestCase
{
    public function testReturnsConfiguredResponseAndRecordsInput(): void
    {
        $fake = new FakeAttributeExtractorClient();
        $fake->setNext(new ExtractedAttributes([new AttributeScore('blue', 0.9)], [], [], []));

        $result = $fake->extract('the-bytes');

        self::assertSame('blue', $result->clothingColors[0]->value);
        self::assertSame('the-bytes', $fake->lastImageBytes);
    }

    public function testDefaultsToEmpty(): void
    {
        self::assertSame([], (new FakeAttributeExtractorClient())->extract('x')->bibs);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/Photo/FakeAttributeExtractorClientTest.php`
Expected: FAIL — class `App\Tests\Fake\FakeAttributeExtractorClient` not found.

- [ ] **Step 3: Write minimal implementation**

`tests/Fake/FakeAttributeExtractorClient.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Service\Photo\AttributeExtractorClientInterface;
use App\Service\Photo\ExtractedAttributes;

/**
 * Test-only fake. Tests configure the next response via setNext();
 * defaults to an empty extraction. Mirrors FakeGoogleOAuthClient's shape.
 */
final class FakeAttributeExtractorClient implements AttributeExtractorClientInterface
{
    public string $lastImageBytes = '';

    private ?ExtractedAttributes $next = null;

    public function setNext(ExtractedAttributes $attributes): void
    {
        $this->next = $attributes;
    }

    public function extract(string $imageBytes): ExtractedAttributes
    {
        $this->lastImageBytes = $imageBytes;

        return $this->next ?? ExtractedAttributes::empty();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Service/Photo/FakeAttributeExtractorClientTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Bind the fake under `when@test`**

In `config/services.yaml`, inside the `when@test:` `services:` block (mirroring the `FakeGoogleOAuthClient` binding ~lines 60–67), add:
```yaml
        App\Tests\Fake\FakeAttributeExtractorClient:
            public: true

        App\Service\Photo\AttributeExtractorClientInterface:
            alias: App\Tests\Fake\FakeAttributeExtractorClient
            public: true
```

- [ ] **Step 6: Verify test-env wiring + full suite**

Run: `APP_ENV=test bin/console lint:container && vendor/bin/phpunit tests/Unit/Service/Photo`
Expected: container lints clean in test env; all Service/Photo unit tests pass.

- [ ] **Step 7: Commit (human runs)**

```bash
git add tests/Fake/FakeAttributeExtractorClient.php config/services.yaml tests/Unit/Service/Photo/FakeAttributeExtractorClientTest.php
git commit -m "109 - test fake for AttributeExtractorClient bound under when@test"
```

---

## Self-Review

**1. Spec coverage** (against build spec §"Inference service contract", §"Testing strategy", Plan B scope):
- CPU service, `POST /extract` + `GET /health`, in-memory (no retention), vocab-enforced → Tasks 1–3. ✓
- No face/biometric code paths → only clothing/scene/bib recognizers defined; no face module. ✓
- Compose service (CPU, `unless-stopped` like worker) → Task 4. ✓
- Symfony client copying the `HttpClientInterface` injection pattern (`UpdateMmdbCommand`) → Task 6. ✓
- Scoped HTTP client + base URI + timeout → Task 6 (`config/packages/http_client.yaml`). ✓
- Re-validate against allowlist PHP-side, drop unknowns → Task 6 (`AttributeVocabulary`). ✓
- Test fake under `when@test` mirroring `FakeGoogleOAuthClient` → Task 7. ✓
- Shared contract names for Plan C (`AttributeExtractorClientInterface::extract(string): ExtractedAttributes`) → documented in header + Tasks 5–6. ✓

**2. Placeholder scan:** No TBD/TODO; every code step shows complete code; the model plug points in `StubRecognizer` are an intentional, documented seam, not a placeholder (service is fully functional and tested with the stub). ✓

**3. Type consistency:** `ExtractedAttributes` accessors (`clothingColors`, `clothingTypes`, `scenes`, `bibs`) and `AttributeScore(value, confidence)` are used identically in Tasks 5, 6, 7. `extract(string $imageBytes): ExtractedAttributes` is stable across interface, real client, and fake. Python `Score`/`RawResult` fields match the JSON keys mapped in PHP. ✓

**4. phpmnd/PSR-12:** HTTP status literals live in named constants (`HTTP_OK`, `_UNPROCESSABLE` in Python). Bib confidence threshold is intentionally NOT here — it belongs to Plan C's handler. ✓

## Notes for Plan C
- Inject `AttributeExtractorClientInterface` (not the concrete class) so the fake swaps in tests.
- The client already drops out-of-vocabulary clothing/scene values and returns bibs unfiltered — Plan C applies the `≥ 0.80` confidence threshold, the per-event toggle, and the suppress-list.
- `ExtractedAttributes::empty()` is returned on any service/transport error, so the handler should treat "no attributes" as a non-fatal outcome (leave the photo `Ready`, no tags).
