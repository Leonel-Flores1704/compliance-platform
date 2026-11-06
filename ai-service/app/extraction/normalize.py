import re

_HYPHENATED_BREAK = re.compile(r"(\w)-[ \t]*\r?\n[ \t]*(\w)")
_REPEATED_SPACES = re.compile(r"[ \t\f\v ]+")
_EXTRA_BLANK_LINES = re.compile(r"\n{3,}")


def normalize_text(text: str) -> str:
    """Remove null characters, join hyphenated line breaks and collapse repeated spaces."""
    text = text.replace("\x00", "")
    text = text.replace("\r\n", "\n").replace("\r", "\n")
    text = _HYPHENATED_BREAK.sub(r"\1\2", text)
    text = _REPEATED_SPACES.sub(" ", text)
    text = "\n".join(line.strip() for line in text.split("\n"))
    text = _EXTRA_BLANK_LINES.sub("\n\n", text)
    return text.strip()
