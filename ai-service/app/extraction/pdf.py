import io

import pdfplumber

from app.extraction.errors import ExtractionError, OcrRequiredError
from app.extraction.normalize import normalize_text
from app.extraction.segment import Segment

# A PDF whose average extracted text per page is below this value is treated as scanned.
SCANNED_MIN_AVG_CHARS_PER_PAGE = 20


def extract_pdf(file_bytes: bytes) -> list[Segment]:
    try:
        with pdfplumber.open(io.BytesIO(file_bytes)) as pdf:
            page_texts = [normalize_text(page.extract_text() or "") for page in pdf.pages]
    except Exception as exc:  # pdfminer raises many types for damaged or encrypted files
        raise ExtractionError() from exc

    if not page_texts:
        raise ExtractionError()

    total_chars = sum(len(text) for text in page_texts)
    if total_chars / len(page_texts) < SCANNED_MIN_AVG_CHARS_PER_PAGE:
        raise OcrRequiredError(page_count=len(page_texts))

    return [
        Segment(text=text, page=number, section=None)
        for number, text in enumerate(page_texts, start=1)
        if text
    ]
