"""Access to the CMP-03 test documents (tests/fixtures/documents, ADR-014).

Only cases that the CMP-03 set does not cover (DOCX tables, Heading 3, empty DOCX)
are built in memory.
"""

import io
from pathlib import Path

from docx import Document

DOCUMENTS_DIR = Path(__file__).parent / "fixtures" / "documents"

PDF_MIME = "application/pdf"
DOCX_MIME = "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
TXT_MIME = "text/plain"


def read(name: str) -> bytes:
    """Read a fixture document, e.g. read("information_security_policy.pdf") or read("invalid/corrupted.pdf")."""
    return (DOCUMENTS_DIR / name).read_bytes()


def docx_with_table_and_heading_3() -> bytes:
    document = Document()
    document.add_heading("Access Control", level=2)
    document.add_paragraph("Access is granted by role.")
    table = document.add_table(rows=2, cols=2)
    table.cell(0, 0).text = "Role"
    table.cell(0, 1).text = "Permission"
    table.cell(1, 0).text = "Administrator"
    table.cell(1, 1).text = "Manage users"
    document.add_heading("Minor heading", level=3)
    document.add_paragraph("Level 3 headings do not start a new section.")
    return _save(document)


def empty_docx() -> bytes:
    return _save(Document())


def _save(document) -> bytes:
    buffer = io.BytesIO()
    document.save(buffer)
    return buffer.getvalue()
