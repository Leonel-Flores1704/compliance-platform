from dataclasses import asdict, dataclass


@dataclass(frozen=True)
class Segment:
    """A piece of extracted text with its location in the source document.

    page starts at 1 and is only set for PDFs. section is the heading that
    contains the text (DOCX and TXT), or None when there is no heading.
    """

    text: str
    page: int | None = None
    section: str | None = None

    def to_dict(self) -> dict:
        return asdict(self)
