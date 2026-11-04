import pytest

from app.extraction import (
    DAMAGED_FILE_MESSAGE,
    NO_TEXT_MESSAGE,
    ExtractionError,
    OcrRequiredError,
    UnsupportedFormatError,
    extract,
)
from app.extraction.normalize import normalize_text
from tests import documents
from tests.documents import DOCX_MIME, PDF_MIME, TXT_MIME

TEXT_PDFS = [
    "licencia_ambiental_unica_2026.pdf",
    "politica_riesgos_psicosociales_v1.pdf",
    "politica_riesgos_psicosociales_v2.pdf",
    "information_security_policy.pdf",
    "programa_interno_proteccion_civil_2026.pdf",
    "supplier_code_of_conduct.pdf",
    "aviso_privacidad_integral.pdf",
    "internal_audit_report_2025.pdf",
]
SCANNED_PDFS = [
    "dictamen_instalaciones_electricas_escaneado.pdf",
    "constancia_uso_suelo_escaneada.pdf",
    "fire_extinguisher_inspection_scanned.pdf",
    "invalid/blank_scan.pdf",
]
DOCX_FILES = [
    "procedimiento_atencion_emergencias.docx",
    "data_retention_disposal_procedure.docx",
    "reglamento_interior_trabajo_extracto.docx",
    "lockout_tagout_procedure.docx",
]
TXT_FILES = [
    "minuta_comite_seguridad_2026_09.txt",
    "training_log_summary_2026.txt",
    "directorio_emergencias.txt",
]


# --- Every CMP-03 fixture ----------------------------------------------------


@pytest.mark.parametrize(
    ("name", "mime"),
    [(n, PDF_MIME) for n in TEXT_PDFS] + [(n, DOCX_MIME) for n in DOCX_FILES] + [(n, TXT_MIME) for n in TXT_FILES],
)
def test_every_text_fixture_is_extracted(name, mime):
    segments = extract(documents.read(name), mime)

    assert segments
    assert all(segment.text.strip() for segment in segments)
    assert all("\x00" not in segment.text for segment in segments)


@pytest.mark.parametrize("name", TEXT_PDFS)
def test_text_pdf_pages_are_numbered_from_one(name):
    pages = [segment.page for segment in extract(documents.read(name), PDF_MIME)]

    assert pages == list(range(1, len(pages) + 1))


@pytest.mark.parametrize("name", SCANNED_PDFS)
def test_scanned_pdfs_are_sent_to_ocr(name):
    with pytest.raises(OcrRequiredError):
        extract(documents.read(name), PDF_MIME)


# --- PDF -------------------------------------------------------------------


def test_pdf_segments_have_correct_page_numbers():
    segments = extract(documents.read("information_security_policy.pdf"), PDF_MIME)
    by_page = {segment.page: segment.text for segment in segments}

    assert by_page[2].startswith("Access control")
    assert "Passwords must have at least 12 characters" in by_page[2]
    assert by_page[3].startswith("Incident reporting")
    assert "reported within 24 hours" in by_page[3]
    assert by_page[4].startswith("Review and sanctions")
    assert all(segment.section is None for segment in segments)


def test_password_protected_pdf_raises_extraction_error():
    with pytest.raises(ExtractionError) as exc_info:
        extract(documents.read("invalid/password_protected.pdf"), PDF_MIME)

    assert exc_info.value.message == DAMAGED_FILE_MESSAGE


def test_corrupted_pdf_raises_extraction_error():
    with pytest.raises(ExtractionError) as exc_info:
        extract(documents.read("invalid/corrupted.pdf"), PDF_MIME)

    assert exc_info.value.message == DAMAGED_FILE_MESSAGE


# --- DOCX ------------------------------------------------------------------


def test_docx_segments_carry_heading_as_section():
    segments = extract(documents.read("data_retention_disposal_procedure.docx"), DOCX_MIME)

    assert [segment.section for segment in segments] == [
        None,
        "Purpose",
        "Retention periods",
        "Disposal methods",
        "Approvals",
    ]
    assert all(segment.page is None for segment in segments)
    assert "Accounting and tax records: 10 years." in segments[2].text


def test_docx_tables_are_converted_row_by_row():
    segment = extract(documents.docx_with_table_and_heading_3(), DOCX_MIME)[0]

    assert segment.section == "Access Control"
    assert "Role - Permission" in segment.text
    assert "Administrator - Manage users" in segment.text


def test_docx_heading_3_does_not_start_a_section():
    segments = extract(documents.docx_with_table_and_heading_3(), DOCX_MIME)

    assert len(segments) == 1
    assert "Minor heading" in segments[0].text
    assert "Level 3 headings do not start a new section." in segments[0].text


def test_docx_without_text_returns_no_text_message():
    with pytest.raises(ExtractionError) as exc_info:
        extract(documents.empty_docx(), DOCX_MIME)

    assert exc_info.value.message == NO_TEXT_MESSAGE


def test_invalid_docx_raises_extraction_error():
    with pytest.raises(ExtractionError) as exc_info:
        extract(b"this is not a zip package", DOCX_MIME)

    assert exc_info.value.message == DAMAGED_FILE_MESSAGE


# --- TXT -------------------------------------------------------------------


def test_windows_1252_txt_is_converted_to_utf8():
    raw = documents.read("minuta_comite_seguridad_2026_09.txt")
    assert b"COMIT\xc9" in raw  # really Windows-1252 on disk

    segments = extract(raw, TXT_MIME)

    assert segments[0].section == "MINUTA DEL COMITÉ DE SEGURIDAD E HIGIENE"
    assert "Jorge Peña (producción)" in segments[1].text
    assert "polvo químico seco" in segments[2].text
    assert "El último simulacro registró un tiempo de evacuación" in segments[3].text
    assert all(segment.page is None for segment in segments)


def test_short_windows_1252_title_keeps_accents():
    segments = extract("Política de Seguridad".encode("cp1252"), TXT_MIME)

    assert segments[0].text == "Política de Seguridad"


def test_txt_sections_split_on_uppercase_and_underlined_lines():
    content = (
        "Preamble line.\nSCOPE\nApplies to all staff.\nDefinitions\n---\nA term is defined here.\n"
    ).encode("utf-8")

    segments = extract(content, TXT_MIME)

    assert [(s.section, s.text) for s in segments] == [
        (None, "Preamble line."),
        ("SCOPE", "Applies to all staff."),
        ("Definitions", "A term is defined here."),
    ]


def test_empty_txt_returns_no_text_message():
    with pytest.raises(ExtractionError) as exc_info:
        extract(documents.read("invalid/empty.txt"), TXT_MIME)

    assert exc_info.value.message == NO_TEXT_MESSAGE


# --- Normalization and dispatch ---------------------------------------------


def test_normalize_text():
    raw = "Com-\npliance   rules\x00 apply\t\tto   all.\n\n\n\nEnd"

    assert normalize_text(raw) == "Compliance rules apply to all.\n\nEnd"


def test_unsupported_mime_type_raises():
    with pytest.raises(UnsupportedFormatError):
        extract(b"data", "application/zip")


def test_mime_type_parameters_are_ignored():
    segments = extract(b"Hello world", "text/plain; charset=utf-8")

    assert segments[0].text == "Hello world"
