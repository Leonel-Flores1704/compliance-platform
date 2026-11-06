DAMAGED_FILE_MESSAGE = "The file could not be read. It may be damaged or password-protected."
NO_TEXT_MESSAGE = "No text could be extracted from this document."


class ExtractionError(Exception):
    """Text could not be extracted. Mapped to 422 EXTRACTION_FAILED by the API."""

    def __init__(self, message: str = DAMAGED_FILE_MESSAGE) -> None:
        super().__init__(message)
        self.message = message


class OcrRequiredError(Exception):
    """The PDF has no usable text layer (scanned). It must be processed by OCR (CMP-26)."""

    def __init__(self, page_count: int) -> None:
        super().__init__(f"PDF looks scanned ({page_count} pages); OCR is required.")
        self.page_count = page_count


class UnsupportedFormatError(ValueError):
    """The MIME type is not one of PDF, DOCX or TXT."""
