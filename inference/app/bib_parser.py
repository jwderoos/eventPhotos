from dataclasses import dataclass

from app.bib_config import BIB_REGEX, MIN_BIB_LENGTH, MIN_TEXT_HEIGHT_FRAC, OCR_CONF_FLOOR
from app.recognizer import Score


@dataclass(frozen=True)
class TextLine:
    text: str
    confidence: float
    height_px: float


class BibParser:
    """Turns OCR text lines from one crop into validated bib-number candidates.

    Region-gating (OCR only inside a torso crop) already removed most background
    text; this is the second line of defence. Rules (all must pass), per token:
      1. matches BIB_REGEX (uppercase/digits/one hyphen, ends in a digit run)
      2. OCR confidence >= OCR_CONF_FLOOR
      3. text box height >= MIN_TEXT_HEIGHT_FRAC of the crop height
      4. token length >= MIN_BIB_LENGTH — drops short OCR fragments
    Never emits the printed first name (rule 1 requires a trailing digit run).
    Dedup across crops is BibRecognizer's job, not this class's.
    """

    def parse(self, lines: list[TextLine], crop_height_px: float) -> list[Score]:
        out: list[Score] = []
        min_height = MIN_TEXT_HEIGHT_FRAC * crop_height_px
        for line in lines:
            if line.confidence < OCR_CONF_FLOOR:
                continue
            if line.height_px < min_height:
                continue
            for token in line.text.split():
                if len(token) >= MIN_BIB_LENGTH and BIB_REGEX.match(token):
                    out.append(Score(token, line.confidence))
        return out
