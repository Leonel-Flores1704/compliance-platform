import io

import psycopg
import pytest
from fastapi.testclient import TestClient

from app.extraction import Segment, extract
from app.indexing import embeddings as embeddings_module
from app.indexing.chunking import Chunk, Chunker
from app.indexing.embeddings import EmbeddingError, MockEmbeddingProvider, embed_texts
from app.indexing.pipeline import build_chunker
from app.indexing.store import find_similar_chunks, replace_version_chunks
from app.main import app
from app.security import get_expected_token
from tests import documents
from tests.documents import DOCX_MIME, PDF_MIME, TXT_MIME

client = TestClient(app)
AUTH_HEADERS = {"X-Internal-Token": get_expected_token()}
FAILED_VERSIONS_WITH_CHUNKS_SQL = """
    SELECT count(*) FROM document_chunks c
    JOIN document_versions v ON v.id = c.document_version_id
    WHERE v.status = 'processing_failed'
"""


def ingest(name: str, mime: str, *, organization_id=1, document_id=10, version_id=1):
    return client.post(
        "/v1/documents/ingest",
        headers=AUTH_HEADERS,
        files={"file": (name.split("/")[-1], io.BytesIO(documents.read(name)), mime)},
        data={
            "organization_id": organization_id,
            "document_id": document_id,
            "document_version_id": version_id,
            "mime_type": mime,
            "access_level": "all_staff",
        },
    )


class FailingProvider:
    name = "failing"
    dimensions = 1024

    def __init__(self) -> None:
        self.calls = 0

    def embed(self, texts):
        self.calls += 1
        raise ConnectionError("embedding backend unavailable")


# --- Chunker (no database) ---------------------------------------------------------


def test_chunk_parameters_come_from_adr_013():
    chunker = build_chunker()

    assert (chunker.chunk_size, chunker.overlap, chunker.min_tokens) == (500, 75, 30)


def test_chunks_never_exceed_chunk_size_and_keep_overlap():
    text = " ".join(f"Sentence number {i} explains a compliance rule in detail." for i in range(400))
    chunker = Chunker(chunk_size=100, overlap=20, min_tokens=5)

    chunks = chunker.chunk([Segment(text=text, page=1)])

    assert len(chunks) > 5
    assert all(chunk.token_count <= 100 for chunk in chunks)
    for previous, current in zip(chunks, chunks[1:]):
        tail = previous.content.split()[-3:]
        assert " ".join(tail) in current.content  # trailing words repeated as overlap


def test_chunks_never_mix_sections():
    segments = [
        Segment(text="Intro paragraph.", section=None),
        Segment(text="Passwords have 12 characters.", section="Access control"),
        Segment(text="Report incidents in 24 hours.", section="Incident reporting"),
    ]

    chunks = Chunker().chunk(segments)

    assert [chunk.section for chunk in chunks] == [None, "Access control", "Incident reporting"]
    assert [chunk.index for chunk in chunks] == [0, 1, 2]


def test_pdf_chunks_never_cross_pages():
    segments = extract(documents.read("information_security_policy.pdf"), PDF_MIME)

    chunks = Chunker().chunk(segments)

    assert [chunk.page_start for chunk in chunks] == [1, 2, 3, 4]
    assert all(chunk.page_start == chunk.page_end for chunk in chunks)
    assert "12 characters" in chunks[1].content


def test_pdf_chunks_keep_page_range():
    segments = extract(documents.read("information_security_policy.pdf"), PDF_MIME)

    chunks = Chunker(chunk_size=60, overlap=10, min_tokens=5).chunk(segments)

    assert all(chunk.page_start is not None and chunk.page_end >= chunk.page_start for chunk in chunks)
    assert {chunk.page_start for chunk in chunks} >= {1, 2, 3, 4}
    page_two = next(chunk for chunk in chunks if "12 characters" in chunk.content)
    assert page_two.page_start <= 2 <= page_two.page_end


def test_small_trailing_chunk_is_merged_with_previous_chunk_of_same_section():
    words = " ".join(["policy"] * 90) + ". Done."
    chunks = Chunker(chunk_size=50, overlap=0, min_tokens=30).chunk([Segment(text=words, section="S")])

    assert all(chunk.token_count >= 30 for chunk in chunks)
    assert chunks[-1].content.endswith("Done.")


def test_very_long_word_is_split_by_tokens():
    chunks = Chunker(chunk_size=50, overlap=0, min_tokens=1).chunk([Segment(text="x" * 5000)])

    assert all(chunk.token_count <= 50 for chunk in chunks)
    assert "".join(chunk.content for chunk in chunks) == "x" * 5000


# --- Embeddings (no database) ---------------------------------------------------------


def test_embeddings_are_sent_in_batches_of_64():
    sizes = []

    class RecordingProvider(MockEmbeddingProvider):
        def embed(self, texts):
            sizes.append(len(texts))
            return super().embed(texts)

    vectors = embed_texts([f"text {i}" for i in range(150)], RecordingProvider())

    assert sizes == [64, 64, 22]
    assert len(vectors) == 150


def test_embedding_failure_retries_three_times_with_exponential_backoff():
    provider, sleeps = FailingProvider(), []

    with pytest.raises(EmbeddingError):
        embed_texts(["text"], provider, sleep=sleeps.append)

    assert provider.calls == 4  # first attempt + 3 retries
    assert sleeps == [1.0, 2.0, 4.0]


def test_embedding_recovers_after_a_transient_failure():
    class FlakyProvider(MockEmbeddingProvider):
        calls = 0

        def embed(self, texts):
            self.calls += 1
            if self.calls < 3:
                raise TimeoutError("busy")
            return super().embed(texts)

    vectors = embed_texts(["a", "b"], FlakyProvider(), sleep=lambda _: None)

    assert len(vectors) == 2


# --- Ingest and storage (database) ------------------------------------------------------


def test_pdf_ingest_stores_traceable_chunks(database):
    database.create_version()

    r = ingest("information_security_policy.pdf", PDF_MIME)

    assert r.status_code == 200
    rows = database.execute(
        "SELECT document_id, document_version_id, chunk_index, page_start, page_end, is_current "
        "FROM document_chunks ORDER BY chunk_index"
    )
    assert r.json()["chunks"] == len(rows) > 0
    assert r.json()["pages"] == 4
    assert [row[2] for row in rows] == list(range(len(rows)))
    for document_id, version_id, _, page_start, page_end, is_current in rows:
        assert (document_id, version_id) == (10, 1)
        assert page_start is not None and page_end >= page_start
        assert is_current is False  # activated later by set-current


def test_docx_ingest_stores_heading_as_section(database):
    database.create_version()

    r = ingest("data_retention_disposal_procedure.docx", DOCX_MIME)

    assert r.status_code == 200
    sections = [row[0] for row in database.execute("SELECT section FROM document_chunks ORDER BY chunk_index")]
    assert {"Purpose", "Retention periods", "Disposal methods", "Approvals"} <= set(sections)
    assert database.scalar("SELECT count(*) FROM document_chunks WHERE page_start IS NOT NULL") == 0


def test_txt_ingest_stores_sections(database):
    database.create_version()

    r = ingest("minuta_comite_seguridad_2026_09.txt", TXT_MIME)

    assert r.status_code == 200
    sections = {row[0] for row in database.execute("SELECT section FROM document_chunks")}
    assert "MINUTA DEL COMITÉ DE SEGURIDAD E HIGIENE" in sections


def test_reingesting_a_version_replaces_its_chunks(database):
    database.create_version()

    first = ingest("information_security_policy.pdf", PDF_MIME).json()["chunks"]
    second = ingest("information_security_policy.pdf", PDF_MIME).json()["chunks"]

    assert first == second == database.scalar("SELECT count(*) FROM document_chunks")


def test_embedding_failure_returns_502_and_leaves_zero_chunks(database, monkeypatch):
    database.create_version()
    provider = FailingProvider()
    monkeypatch.setattr(embeddings_module, "get_embedding_provider", lambda: provider)
    monkeypatch.setattr(embeddings_module.time, "sleep", lambda _: None)

    r = ingest("information_security_policy.pdf", PDF_MIME)

    assert r.status_code == 502
    assert r.json() == {"error_code": "EMBEDDING_FAILED", "message": "The document could not be indexed."}
    assert provider.calls == 4
    assert database.scalar("SELECT count(*) FROM document_chunks WHERE document_version_id = 1") == 0


def test_failed_version_is_never_indexed(database):
    database.create_version(status="processing_failed")

    r = ingest("information_security_policy.pdf", PDF_MIME)

    assert r.status_code == 422
    assert r.json()["error_code"] == "VALIDATION_ERROR"
    assert database.scalar(FAILED_VERSIONS_WITH_CHUNKS_SQL) == 0


def test_extraction_failure_never_writes_chunks(database):
    database.create_version()

    r = ingest("invalid/password_protected.pdf", PDF_MIME)

    assert r.status_code == 422
    assert database.scalar("SELECT count(*) FROM document_chunks") == 0


def test_version_from_another_organization_is_rejected(database):
    database.create_version(organization_id=1)

    r = ingest("information_security_policy.pdf", PDF_MIME, organization_id=2)

    assert r.status_code == 422
    assert database.scalar("SELECT count(*) FROM document_chunks") == 0


def test_chunks_of_a_version_are_stored_all_or_nothing(database):
    database.create_version()
    ingest("information_security_policy.pdf", PDF_MIME)
    before = database.scalar("SELECT count(*) FROM document_chunks")
    chunks = [
        Chunk(index=0, content="valid fragment", page_start=1, page_end=1, section=None, token_count=2),
        Chunk(index=1, content="broken \x00 fragment", page_start=2, page_end=2, section=None, token_count=3),
    ]
    vectors = MockEmbeddingProvider().embed([chunk.content for chunk in chunks])

    with psycopg.connect(database.url) as conn, pytest.raises(psycopg.Error):
        replace_version_chunks(
            conn, organization_id=1, document_id=10, version_id=1, chunks=chunks, embeddings=vectors
        )

    # The failed replacement rolled back: the previous chunks are intact, no partial rows.
    assert database.scalar("SELECT count(*) FROM document_chunks") == before
    assert database.scalar("SELECT count(*) FROM document_chunks WHERE content = 'valid fragment'") == 0


# --- Current version and retrieval (database) -----------------------------------------------


def set_current(document_id: int, version_id: int):
    return client.post(
        f"/v1/documents/{document_id}/set-current",
        headers=AUTH_HEADERS,
        json={"document_version_id": version_id},
    )


def retrieve(database, question: str, allowed=(10,)):
    query = MockEmbeddingProvider().embed([question])[0]
    with psycopg.connect(database.url) as conn:
        return find_similar_chunks(conn, organization_id=1, allowed_document_ids=list(allowed), query_embedding=query)


def test_after_version_2_becomes_current_retrieval_returns_only_its_chunks(database):
    database.create_version(version_id=1, label="1.0")
    database.create_version(version_id=2, label="2.0")
    assert ingest("politica_riesgos_psicosociales_v1.pdf", PDF_MIME, version_id=1).status_code == 200
    assert ingest("politica_riesgos_psicosociales_v2.pdf", PDF_MIME, version_id=2).status_code == 200
    question = "¿Cada cuánto se aplica el cuestionario de identificación?"

    assert retrieve(database, question) == []  # nothing is current yet

    assert set_current(10, 1).status_code == 200
    assert {chunk.document_version_id for chunk in retrieve(database, question)} == {1}

    assert set_current(10, 2).json() == {"status": "ok"}
    results = retrieve(database, question)
    assert results
    assert {chunk.document_version_id for chunk in results} == {2}
    assert database.scalar("SELECT count(*) FROM document_chunks WHERE document_version_id = 1 AND is_current") == 0


def test_retrieval_only_returns_allowed_documents(database):
    database.create_version(document_id=10, version_id=1)
    database.create_version(document_id=11, version_id=2)
    ingest("information_security_policy.pdf", PDF_MIME, document_id=10, version_id=1)
    ingest("supplier_code_of_conduct.pdf", PDF_MIME, document_id=11, version_id=2)
    set_current(10, 1)
    set_current(11, 2)

    results = retrieve(database, "gifts from suppliers", allowed=(11,))

    assert results and {chunk.document_id for chunk in results} == {11}


def test_set_current_rejects_a_version_of_another_document(database):
    database.create_version(document_id=10, version_id=1)
    database.create_version(document_id=11, version_id=2)

    r = set_current(10, 2)

    assert r.status_code == 422
    assert r.json()["error_code"] == "VALIDATION_ERROR"


def test_set_current_rejects_a_failed_version(database):
    database.create_version(version_id=1, status="processing_failed")

    assert set_current(10, 1).status_code == 422
