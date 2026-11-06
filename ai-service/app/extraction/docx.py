import io

from docx import Document
from docx.table import Table
from docx.text.paragraph import Paragraph

from app.extraction.errors import ExtractionError
from app.extraction.normalize import normalize_text
from app.extraction.segment import Segment

SECTION_HEADING_STYLES = {"Heading 1", "Heading 2"}
TABLE_CELL_SEPARATOR = " - "


def extract_docx(file_bytes: bytes) -> list[Segment]:
    try:
        document = Document(io.BytesIO(file_bytes))
        blocks = list(_iter_blocks(document))
    except Exception as exc:  # BadZipFile, PackageNotFoundError, KeyError, lxml errors...
        raise ExtractionError() from exc

    segments: list[Segment] = []
    section: str | None = None
    lines: list[str] = []

    def flush() -> None:
        text = normalize_text("\n".join(lines))
        if text:
            segments.append(Segment(text=text, page=None, section=section))
        lines.clear()

    for block in blocks:
        if isinstance(block, Paragraph):
            if _is_section_heading(block):
                flush()
                section = normalize_text(block.text) or None
            else:
                lines.append(block.text)
        else:
            lines.extend(_table_rows(block))

    flush()
    return segments


def _iter_blocks(document):
    """Yield paragraphs and tables in document order."""
    for child in document.element.body.iterchildren():
        tag = child.tag.rsplit("}", 1)[-1]
        if tag == "p":
            yield Paragraph(child, document)
        elif tag == "tbl":
            yield Table(child, document)


def _is_section_heading(paragraph: Paragraph) -> bool:
    style = paragraph.style
    return style is not None and style.name in SECTION_HEADING_STYLES and bool(paragraph.text.strip())


def _table_rows(table: Table) -> list[str]:
    rows = []
    for row in table.rows:
        cells: list[str] = []
        previous_cell = None
        for cell in row.cells:
            # Merged cells are returned once per grid column; keep them only once.
            if cell._tc is previous_cell:
                continue
            previous_cell = cell._tc
            cells.append(" ".join(cell.text.split()))
        if any(cells):
            rows.append(TABLE_CELL_SEPARATOR.join(cells))
    return rows
