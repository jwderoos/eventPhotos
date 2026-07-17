import re

from app.bib_parser import BibParser, TextLine
from app.recognizer import Score


CROP_H = 1000.0
TALL = 100.0  # 10% of crop height, above MIN_TEXT_HEIGHT_FRAC


def _parse(text, conf=0.9, height=TALL):
    return BibParser().parse([TextLine(text, conf, height)], CROP_H)


def test_plain_numeric_bib_accepted():
    result = _parse("2236")
    assert result == [Score("2236", 0.9)]


def test_alphanumeric_and_hyphen_bibs_accepted():
    assert _parse("A-142")[0].value == "A-142"
    assert _parse("10K-88")[0].value == "10K-88"


def test_name_line_rejected_no_digit():
    # The printed first name must never be emitted (boundary + no-name rule).
    assert _parse("JOOST") == []


def test_lowercase_rejected():
    assert _parse("abc123") == []


def test_name_and_number_on_one_line_keeps_only_number():
    # OCR sometimes returns "JOOST 2236" as a single line; tokenise on whitespace.
    result = _parse("JOOST 2236")
    assert [s.value for s in result] == ["2236"]


def test_low_confidence_rejected():
    assert _parse("2236", conf=0.30) == []


def test_tiny_text_rejected_by_geometry():
    # 1% of crop height — below MIN_TEXT_HEIGHT_FRAC — likely distant/background.
    assert _parse("2236", height=10.0) == []


def test_overlong_token_rejected():
    assert _parse("1234567890123") == []


def test_dedup_is_not_done_here_returns_each_line():
    parser = BibParser()
    lines = [TextLine("2236", 0.9, TALL), TextLine("2236", 0.8, TALL)]
    assert len(parser.parse(lines, CROP_H)) == 2  # dedup happens in BibRecognizer


def test_short_numeric_fragments_rejected():
    # OCR fragments of occluded/edge bibs (1-2 chars) must not be emitted as bibs.
    assert _parse("6") == []
    assert _parse("94") == []


def test_three_char_bib_accepted():
    assert _parse("438")[0].value == "438"
