"""document_chunks persistence with full traceability (NFR-019, ADR-012)."""

from dataclasses import dataclass

import psycopg

from app.indexing.chunking import Chunk


class VersionNotIndexableError(Exception):
    """The version does not belong to the document/organization, or its processing failed."""


@dataclass(frozen=True)
class RetrievedChunk:
    id: int
    document_id: int
    document_version_id: int
    chunk_index: int
    content: str
    page_start: int | None
    page_end: int | None
    section: str | None
    similarity: float


def vector_literal(vector: list[float]) -> str:
    return "[" + ",".join(f"{value:.8g}" for value in vector) + "]"


def check_version(conn: psycopg.Connection, organization_id: int, document_id: int, version_id: int) -> None:
    row = conn.execute(
        """
        SELECT d.organization_id, v.status
        FROM document_versions v
        JOIN documents d ON d.id = v.document_id
        WHERE v.id = %s AND v.document_id = %s
        """,
        (version_id, document_id),
    ).fetchone()
    if row is None or row[0] != organization_id:
        raise VersionNotIndexableError("The document version does not belong to this document and organization.")
    if row[1] == "processing_failed":
        raise VersionNotIndexableError("A version whose processing failed cannot be indexed.")


def replace_version_chunks(
    conn: psycopg.Connection,
    *,
    organization_id: int,
    document_id: int,
    version_id: int,
    chunks: list[Chunk],
    embeddings: list[list[float]],
) -> int:
    """Store every chunk of a version in one transaction: all rows or none.

    New chunks are stored with is_current = false; set_current_version() activates them.
    """
    if len(chunks) != len(embeddings):
        raise ValueError("chunks and embeddings must have the same length")

    with conn.transaction():
        check_version(conn, organization_id, document_id, version_id)
        conn.execute("DELETE FROM document_chunks WHERE document_version_id = %s", (version_id,))
        with conn.cursor() as cursor:
            cursor.executemany(
                """
                INSERT INTO document_chunks
                    (organization_id, document_id, document_version_id, chunk_index, content,
                     page_start, page_end, section, is_current, embedding)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, false, %s::vector)
                """,
                [
                    (
                        organization_id,
                        document_id,
                        version_id,
                        chunk.index,
                        chunk.content,
                        chunk.page_start,
                        chunk.page_end,
                        chunk.section,
                        vector_literal(embedding),
                    )
                    for chunk, embedding in zip(chunks, embeddings)
                ],
            )
    return len(chunks)


def set_current_version(conn: psycopg.Connection, document_id: int, version_id: int) -> int:
    """Mark the chunks of ``version_id`` as current and every other version of the document as not current."""
    with conn.transaction():
        row = conn.execute(
            "SELECT status FROM document_versions WHERE id = %s AND document_id = %s",
            (version_id, document_id),
        ).fetchone()
        if row is None:
            raise VersionNotIndexableError("The document version does not belong to this document.")
        if row[0] == "processing_failed":
            raise VersionNotIndexableError("A version whose processing failed cannot be current.")
        conn.execute(
            "UPDATE document_chunks SET is_current = (document_version_id = %s) WHERE document_id = %s",
            (version_id, document_id),
        )
        current = conn.execute(
            "SELECT count(*) FROM document_chunks WHERE document_id = %s AND is_current",
            (document_id,),
        ).fetchone()[0]
    return current


def find_similar_chunks(
    conn: psycopg.Connection,
    *,
    organization_id: int,
    allowed_document_ids: list[int],
    query_embedding: list[float],
    limit: int = 8,
    ef_search: int = 40,
) -> list[RetrievedChunk]:
    """Reference retrieval query from ADR-012 (current versions of allowed documents only)."""
    with conn.transaction():
        conn.execute(f"SET LOCAL hnsw.ef_search = {int(ef_search)}")
        rows = conn.execute(
            """
            SELECT id, document_id, document_version_id, chunk_index, content, page_start, page_end, section,
                   1 - (embedding <=> %(query)s::vector) AS similarity
            FROM document_chunks
            WHERE organization_id = %(organization_id)s
              AND document_id = ANY(%(allowed)s)
              AND is_current = true
            ORDER BY embedding <=> %(query)s::vector
            LIMIT %(limit)s
            """,
            {
                "query": vector_literal(query_embedding),
                "organization_id": organization_id,
                "allowed": list(allowed_document_ids),
                "limit": limit,
            },
        ).fetchall()
    return [RetrievedChunk(*row) for row in rows]
