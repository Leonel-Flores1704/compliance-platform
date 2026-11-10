import io
import json
from pathlib import Path
from uuid import uuid4

from fastapi.testclient import TestClient

from app.indexing import embeddings as embeddings_module
from app.main import app
from app.security import get_expected_token
from tests import documents

client = TestClient(app)
TOKEN = get_expected_token()
AUTH_HEADERS = {"X-Internal-Token": TOKEN}


def test_auth_rejection_across_endpoints():
    # Without token
    assert client.get("/health").status_code == 401
    assert client.post("/v1/documents/ingest").status_code == 401
    assert client.post("/v1/documents/1/set-current").status_code == 401
    assert client.post("/v1/ask").status_code == 401

    # With wrong token
    bad_headers = {"X-Internal-Token": "wrong-token"}
    r = client.get("/health", headers=bad_headers)
    assert r.status_code == 401
    assert r.json() == {
        "error_code": "UNAUTHORIZED",
        "message": "Invalid internal token.",
    }


def test_validation_error_format():
    # Invalid ask request: question too short (less than 3 chars)
    payload = {
        "question": "hi",
        "user_id": 1,
        "organization_id": 1,
        "allowed_document_ids": [1],
        "request_id": str(uuid4()),
    }
    r = client.post("/v1/ask", headers=AUTH_HEADERS, json=payload)
    assert r.status_code == 422
    body = r.json()
    assert body["error_code"] == "VALIDATION_ERROR"
    assert "question" in body["message"]


def test_ingest_success_pdf(database):
    database.create_version()
    files = {"file": ("manual.pdf", io.BytesIO(documents.read("information_security_policy.pdf")), "application/pdf")}
    data = {
        "organization_id": 1,
        "document_id": 10,
        "document_version_id": 1,
        "mime_type": "application/pdf",
        "access_level": "confidential",
    }
    r = client.post("/v1/documents/ingest", headers=AUTH_HEADERS, files=files, data=data)
    assert r.status_code == 200
    res = r.json()
    assert res["status"] == "processed"
    assert res["chunks"] > 0
    assert res["chunks"] == database.scalar("SELECT count(*) FROM document_chunks")
    assert res["pages"] == 4
    assert res["ocr_used"] is False


def test_ingest_success_docx_and_txt(database):
    database.create_version(document_id=11, version_id=1)
    # DOCX
    files = {"file": ("policy.docx", io.BytesIO(documents.read("data_retention_disposal_procedure.docx")), "application/vnd.openxmlformats-officedocument.wordprocessingml.document")}
    data = {
        "organization_id": 1,
        "document_id": 11,
        "document_version_id": 1,
        "mime_type": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        "access_level": "internal",
    }
    r = client.post("/v1/documents/ingest", headers=AUTH_HEADERS, files=files, data=data)
    assert r.status_code == 200

    # TXT
    files = {"file": ("minuta.txt", io.BytesIO(documents.read("minuta_comite_seguridad_2026_09.txt")), "text/plain")}
    data["mime_type"] = "text/plain"
    r = client.post("/v1/documents/ingest", headers=AUTH_HEADERS, files=files, data=data)
    assert r.status_code == 200


def test_ingest_unsupported_file_type():
    files = {"file": ("archive.zip", io.BytesIO(b"mock zip"), "application/zip")}
    data = {
        "organization_id": 1,
        "document_id": 10,
        "document_version_id": 1,
        "mime_type": "application/zip",
        "access_level": "confidential",
    }
    r = client.post("/v1/documents/ingest", headers=AUTH_HEADERS, files=files, data=data)
    assert r.status_code == 415
    assert r.json() == {
        "error_code": "UNSUPPORTED_FILE_TYPE",
        "message": "Only PDF, DOCX and TXT files can be processed.",
    }


def test_ingest_extraction_failed():
    files = {"file": ("manual.pdf", io.BytesIO(documents.read("invalid/corrupted.pdf")), "application/pdf")}
    data = {
        "organization_id": 1,
        "document_id": 10,
        "document_version_id": 1,
        "mime_type": "application/pdf",
        "access_level": "confidential",
    }
    r = client.post("/v1/documents/ingest", headers=AUTH_HEADERS, files=files, data=data)
    assert r.status_code == 422
    assert r.json() == {
        "error_code": "EXTRACTION_FAILED",
        "message": "The file could not be read. It may be damaged or password-protected.",
    }


INGEST_FORM = {
    "organization_id": 1,
    "document_id": 10,
    "document_version_id": 1,
    "access_level": "confidential",
}


def test_ingest_password_protected_pdf_returns_extraction_failed():
    files = {"file": ("protected.pdf", io.BytesIO(documents.read("invalid/password_protected.pdf")), "application/pdf")}
    data = {**INGEST_FORM, "mime_type": "application/pdf"}
    r = client.post("/v1/documents/ingest", headers=AUTH_HEADERS, files=files, data=data)
    assert r.status_code == 422
    assert r.json() == {
        "error_code": "EXTRACTION_FAILED",
        "message": "The file could not be read. It may be damaged or password-protected.",
    }


def test_ingest_file_without_text_returns_no_text_message():
    files = {"file": ("empty.docx", io.BytesIO(documents.empty_docx()), documents.DOCX_MIME)}
    data = {**INGEST_FORM, "mime_type": documents.DOCX_MIME}
    r = client.post("/v1/documents/ingest", headers=AUTH_HEADERS, files=files, data=data)
    assert r.status_code == 422
    assert r.json() == {
        "error_code": "EXTRACTION_FAILED",
        "message": "No text could be extracted from this document.",
    }


def test_ingest_scanned_pdf_is_sent_to_ocr():
    files = {"file": ("scan.pdf", io.BytesIO(documents.read("fire_extinguisher_inspection_scanned.pdf")), "application/pdf")}
    data = {**INGEST_FORM, "mime_type": "application/pdf"}
    r = client.post("/v1/documents/ingest", headers=AUTH_HEADERS, files=files, data=data)
    assert r.status_code == 200
    assert r.json()["ocr_used"] is True
    assert r.json()["pages"] == 2


def test_ingest_uses_extension_when_mime_type_is_generic(database):
    database.create_version()
    files = {"file": ("notes.txt", io.BytesIO("Política de Seguridad".encode("cp1252")), "text/plain")}
    data = {**INGEST_FORM, "mime_type": "application/octet-stream"}
    r = client.post("/v1/documents/ingest", headers=AUTH_HEADERS, files=files, data=data)
    assert r.status_code == 200
    assert r.json()["chunks"] == 1


def test_ingest_ocr_failed():
    files = {"file": ("ocr_failed_scan.pdf", io.BytesIO(b"unreadable scan"), "application/pdf")}
    data = {
        "organization_id": 1,
        "document_id": 10,
        "document_version_id": 1,
        "mime_type": "application/pdf",
        "access_level": "confidential",
    }
    r = client.post("/v1/documents/ingest", headers=AUTH_HEADERS, files=files, data=data)
    assert r.status_code == 422
    assert r.json() == {
        "error_code": "OCR_FAILED",
        "message": "The scanned document could not be read.",
    }


def test_ingest_embedding_failed(database, monkeypatch):
    database.create_version()

    class FailingProvider:
        name = "failing"
        dimensions = 1024

        def embed(self, texts):
            raise ConnectionError("embedding backend unavailable")

    monkeypatch.setattr(embeddings_module, "get_embedding_provider", FailingProvider)
    monkeypatch.setattr(embeddings_module.time, "sleep", lambda _: None)
    files = {"file": ("manual.pdf", io.BytesIO(documents.read("information_security_policy.pdf")), "application/pdf")}
    data = {
        "organization_id": 1,
        "document_id": 10,
        "document_version_id": 1,
        "mime_type": "application/pdf",
        "access_level": "confidential",
    }
    r = client.post("/v1/documents/ingest", headers=AUTH_HEADERS, files=files, data=data)
    assert r.status_code == 502
    assert r.json() == {
        "error_code": "EMBEDDING_FAILED",
        "message": "The document could not be indexed.",
    }


def test_ingest_provider_not_authorized():
    files = {"file": ("provider_not_authorized_doc.pdf", io.BytesIO(documents.read("information_security_policy.pdf")), "application/pdf")}
    data = {
        "organization_id": 1,
        "document_id": 10,
        "document_version_id": 1,
        "mime_type": "application/pdf",
        "access_level": "confidential",
    }
    r = client.post("/v1/documents/ingest", headers=AUTH_HEADERS, files=files, data=data)
    assert r.status_code == 503
    assert r.json() == {
        "error_code": "PROVIDER_NOT_AUTHORIZED",
        "message": "The AI provider is not authorized.",
    }


def test_set_current_version_success(database):
    database.create_version(document_id=10, version_id=7)
    r = client.post(
        "/v1/documents/10/set-current",
        headers=AUTH_HEADERS,
        json={"document_version_id": 7},
    )
    assert r.status_code == 200
    assert r.json() == {"status": "ok"}


def test_set_current_version_validation_error():
    r = client.post(
        "/v1/documents/10/set-current",
        headers=AUTH_HEADERS,
        json={"document_version_id": "not-an-int"},
    )
    assert r.status_code == 422
    assert r.json()["error_code"] == "VALIDATION_ERROR"


def test_ask_success():
    payload = {
        "question": "What is the retention requirement for compliance audits?",
        "user_id": 1,
        "organization_id": 1,
        "allowed_document_ids": [10, 11],
        "request_id": str(uuid4()),
    }
    r = client.post("/v1/ask", headers=AUTH_HEADERS, json=payload)
    assert r.status_code == 200
    data = r.json()
    assert "answer" in data
    assert data["status"] in ("answered", "insufficient_information")
    assert isinstance(data["citations"], list)
    assert len(data["citations"]) > 0
    citation = data["citations"][0]
    assert "document_id" in citation
    assert "document_version_id" in citation
    assert "document_name" in citation
    assert "page" in citation
    assert "section" in citation
    assert "chunk_id" in citation
    assert "score" in citation
    assert "disclaimer" in data
    assert "latency_ms" in data


def test_ask_llm_timeout():
    payload = {
        "question": "Please simulate_timeout for testing LLM response time limits",
        "user_id": 1,
        "organization_id": 1,
        "allowed_document_ids": [10],
        "request_id": str(uuid4()),
    }
    r = client.post("/v1/ask", headers=AUTH_HEADERS, json=payload)
    assert r.status_code == 504
    assert r.json() == {
        "error_code": "LLM_TIMEOUT",
        "message": "The language model did not respond in time.",
    }


def test_ask_provider_not_authorized():
    payload = {
        "question": "Please simulate_provider_not_authorized query",
        "user_id": 1,
        "organization_id": 1,
        "allowed_document_ids": [10],
        "request_id": str(uuid4()),
    }
    r = client.post("/v1/ask", headers=AUTH_HEADERS, json=payload)
    assert r.status_code == 503
    assert r.json() == {
        "error_code": "PROVIDER_NOT_AUTHORIZED",
        "message": "The AI provider is not authorized.",
    }


def test_openapi_file_matches_contract():
    openapi_path = Path(__file__).resolve().parent.parent / "openapi.json"
    assert openapi_path.exists(), "ai-service/openapi.json must exist"

    with open(openapi_path, encoding="utf-8") as f:
        spec = json.load(f)

    # Must contain the 4 endpoints
    paths = spec.get("paths", {})
    assert "/health" in paths
    assert "/v1/documents/ingest" in paths
    assert "/v1/documents/{document_id}/set-current" in paths
    assert "/v1/ask" in paths

    # Verify all 8 error codes are present in the specification
    spec_str = json.dumps(spec)
    expected_error_codes = [
        "UNAUTHORIZED",
        "VALIDATION_ERROR",
        "UNSUPPORTED_FILE_TYPE",
        "EXTRACTION_FAILED",
        "OCR_FAILED",
        "EMBEDDING_FAILED",
        "PROVIDER_NOT_AUTHORIZED",
        "LLM_TIMEOUT",
    ]
    for code in expected_error_codes:
        assert code in spec_str, f"Error code {code} must be present in openapi.json"
