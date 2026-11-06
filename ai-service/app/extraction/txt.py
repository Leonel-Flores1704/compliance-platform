import re

from charset_normalizer import from_bytes

from app.extraction.errors import ExtractionError
from app.extraction.normalize import normalize_text
from app.extraction.segment import Segment

_UNDERLINE = re.compile(r"^\s*(={3,}|-{3,})\s*$")
PREFERRED_SINGLE_BYTE_ENCODING = "cp1252"


def decode_text(file_bytes: bytes) -> str:
    """Decode bytes to str. UTF-8 is tried first, then charset-normalizer detection."""
    if file_bytes.startswith(b"\xef\xbb\xbf"):
        file_bytes = file_bytes[3:]
    try:
        return file_bytes.decode("utf-8")
    except UnicodeDecodeError:
        pass

    results = from_bytes(file_bytes)
    best = results.best()
    if best is None:
        raise ExtractionError()

    # Short Spanish texts score the same in cp1250 and cp1252, and the detector can pick
    # cp1250 (turning "ñ" into "ń"). Documents are Spanish/English, so cp1252 wins ties.
    for match in results:
        tied_with_best = match.chaos <= best.chaos and match.coherence >= best.coherence
        if PREFERRED_SINGLE_BYTE_ENCODING in match.could_be_from_charset and tied_with_best:
            try:
                return file_bytes.decode(PREFERRED_SINGLE_BYTE_ENCODING)
            except UnicodeDecodeError:
                break
    return str(best)


def extract_txt(file_bytes: bytes) -> list[Segment]:
    text = decode_text(file_bytes).replace("\x00", "")
    lines = text.replace("\r\n", "\n").replace("\r", "\n").split("\n")

    segments: list[Segment] = []
    section: str | None = None
    body: list[str] = []

    def flush() -> None:
        content = normalize_text("\n".join(body))
        if content:
            segments.append(Segment(text=content, page=None, section=section))
        body.clear()

    i = 0
    while i < len(lines):
        line = lines[i]
        next_line = lines[i + 1] if i + 1 < len(lines) else ""

        if line.strip() and _UNDERLINE.match(next_line) and not _UNDERLINE.match(line):
            flush()
            section = normalize_text(line)
            i += 2
            continue
        if _is_uppercase_heading(line):
            flush()
            section = normalize_text(line)
            i += 1
            continue

        body.append(line)
        i += 1

    flush()
    return segments


def _is_uppercase_heading(line: str) -> bool:
    letters = [char for char in line if char.isalpha()]
    return len(letters) >= 2 and all(char.isupper() for char in letters)
