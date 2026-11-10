import os
from dataclasses import dataclass
from urllib.parse import quote

import psycopg
import pytest

TEST_SCHEMA = "ai_test"
EMBEDDING_DIM = 1024

# Minimal copy of the Laravel tables used by the AI service (CMP-06 migration).
# Created in a dedicated schema so a local development database is never touched.
SCHEMA_SQL = f"""
CREATE EXTENSION IF NOT EXISTS vector;
DROP SCHEMA IF EXISTS {TEST_SCHEMA} CASCADE;
CREATE SCHEMA {TEST_SCHEMA};
SET search_path TO {TEST_SCHEMA}, public;
CREATE TABLE organizations (id bigserial PRIMARY KEY);
CREATE TABLE documents (
    id bigserial PRIMARY KEY,
    organization_id bigint NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
    access_level varchar(20) NOT NULL DEFAULT 'all_staff',
    is_archived boolean NOT NULL DEFAULT false
);
CREATE TABLE document_versions (
    id bigserial PRIMARY KEY,
    document_id bigint NOT NULL REFERENCES documents(id) ON DELETE RESTRICT,
    version_label varchar(20) NOT NULL DEFAULT '1.0',
    status varchar(20) NOT NULL DEFAULT 'processing',
    is_current boolean NOT NULL DEFAULT false
);
CREATE TABLE document_chunks (
    id bigserial PRIMARY KEY,
    organization_id bigint NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
    document_id bigint NOT NULL REFERENCES documents(id) ON DELETE RESTRICT,
    document_version_id bigint NOT NULL REFERENCES document_versions(id) ON DELETE RESTRICT,
    chunk_index integer NOT NULL,
    content text NOT NULL,
    page_start integer,
    page_end integer,
    section varchar(255),
    is_current boolean NOT NULL DEFAULT false,
    created_at timestamp DEFAULT CURRENT_TIMESTAMP,
    embedding vector({EMBEDDING_DIM}) NOT NULL,
    UNIQUE (document_version_id, chunk_index)
);
CREATE INDEX document_chunks_embedding_hnsw_idx ON document_chunks USING hnsw (embedding vector_cosine_ops);
"""


@pytest.fixture(autouse=True)
def provider_env(monkeypatch, tmp_path):
    """Tests use the mock providers (allowed only in APP_ENV=testing, ADR-011)."""
    monkeypatch.setenv("APP_ENV", "testing")
    monkeypatch.setenv("LLM_PROVIDER", "mock")
    monkeypatch.setenv("EMBEDDING_PROVIDER", "mock")
    monkeypatch.setenv("EMBEDDING_DIM", str(EMBEDDING_DIM))
    monkeypatch.delenv("LLM_BASE_URL", raising=False)
    monkeypatch.delenv("GROQ_API_KEY", raising=False)
    monkeypatch.setenv("AI_SECURITY_LOG_PATH", str(tmp_path / "logs" / "ai-security.log"))
    return tmp_path / "logs" / "ai-security.log"


def _test_database_url() -> str | None:
    base = os.getenv("TEST_DATABASE_URL")
    if not base:
        return None
    separator = "&" if "?" in base else "?"
    return f"{base}{separator}options={quote(f'-c search_path={TEST_SCHEMA},public')}"


@pytest.fixture(scope="session")
def database_url():
    url = _test_database_url()
    if url is None:
        if os.getenv("REQUIRE_DB_TESTS") == "1":
            pytest.fail("TEST_DATABASE_URL is required (REQUIRE_DB_TESTS=1).")
        pytest.skip("TEST_DATABASE_URL is not set; database tests skipped.")
    with psycopg.connect(url, autocommit=True) as conn:
        conn.execute(SCHEMA_SQL)
    return url


@dataclass
class Database:
    url: str

    def execute(self, sql: str, params: tuple = ()):
        with psycopg.connect(self.url, autocommit=True) as conn:
            cursor = conn.execute(sql, params)
            return cursor.fetchall() if cursor.description else None

    def scalar(self, sql: str, params: tuple = ()):
        return self.execute(sql, params)[0][0]

    def create_version(
        self,
        organization_id: int = 1,
        document_id: int = 10,
        version_id: int = 1,
        status: str = "processing",
        label: str = "1.0",
    ) -> None:
        self.execute("INSERT INTO organizations (id) VALUES (%s) ON CONFLICT DO NOTHING", (organization_id,))
        self.execute(
            "INSERT INTO documents (id, organization_id) VALUES (%s, %s) ON CONFLICT DO NOTHING",
            (document_id, organization_id),
        )
        self.execute(
            "INSERT INTO document_versions (id, document_id, status, version_label) VALUES (%s, %s, %s, %s)",
            (version_id, document_id, status, label),
        )


@pytest.fixture
def database(database_url, monkeypatch) -> Database:
    """Empty test tables, and the app pointed at them through DATABASE_URL."""
    monkeypatch.setenv("DATABASE_URL", database_url)
    db = Database(database_url)
    db.execute("TRUNCATE document_chunks, document_versions, documents, organizations RESTART IDENTITY CASCADE")
    return db
