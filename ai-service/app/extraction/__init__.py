"""Text extraction for PDF, DOCX and TXT files (CMP-25, FR-003)."""

from app.extraction.docx import extract_docx
from app.extraction.errors import (
    DAMAGED_FILE_MESSAGE,
    NO_TEXT_MESSAGE,
    ExtractionError,
    OcrRequiredError,
    UnsupportedFormatError,
)
from app.extraction.pdf import extract_pdf
from app.extraction.segment import Segment
from app.extraction.txt import extract_txt

PDF_MIME_TYPES = {"application/pdf"}
DOCX_MIME_TYPES = {
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "application/docx",
}
TXT_MIME_TYPES = {"text/plain"}

MIME_BY_EXTENSION = {
    ".pdf": "application/pdf",
    ".docx": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    ".txt": "text/plain",
}


def extract(file_bytes: bytes, mime_type: str) -> list[Segment]:
    """Extract text segments from a PDF, DOCX or TXT file.

    Raises:
        ExtractionError: the file is damaged, password-protected or has no text.
        OcrRequiredError: the PDF has no usable text layer and must go to OCR.
        UnsupportedFormatError: the MIME type is not PDF, DOCX or TXT.
    """
    mime = mime_type.split(";", 1)[0].strip().lower()

    if mime in PDF_MIME_TYPES:
        segments = extract_pdf(file_bytes)
    elif mime in DOCX_MIME_TYPES:
        segments = extract_docx(file_bytes)
    elif mime in TXT_MIME_TYPES:
        segments = extract_txt(file_bytes)
    else:
        raise UnsupportedFormatError(mime_type)

    if not segments:
        raise ExtractionError(NO_TEXT_MESSAGE)
    return segments


__all__ = [
    "DAMAGED_FILE_MESSAGE",
    "MIME_BY_EXTENSION",
    "NO_TEXT_MESSAGE",
    "ExtractionError",
    "OcrRequiredError",
    "Segment",
    "UnsupportedFormatError",
    "extract",
]
