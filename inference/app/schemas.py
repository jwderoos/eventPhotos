from pydantic import BaseModel


class ScoreOut(BaseModel):
    value: str
    confidence: float


class ExtractResponse(BaseModel):
    clothing_colors: list[ScoreOut] = []
    clothing_types: list[ScoreOut] = []
    scenes: list[ScoreOut] = []
    bibs: list[ScoreOut] = []
