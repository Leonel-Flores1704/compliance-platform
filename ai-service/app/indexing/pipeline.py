"""Chunk -> embed -> store, for one document version (CMP-29)."""

from dataclasses import dataclass

from app import db
from app.extraction import Segment
from app.indexing.chunking import Chunker
from app.indexing.config import get_rag_config
from app.indexing.embeddings import EmbeddingProvider, embed_texts
from app.indexing.store import replace_version_chunks


@dataclass(frozen=True)
class IndexingResult:
    chunks: int


def build_chunker() -> Chunker:
    config = get_rag_config().chunking
    return Chunker(
        chunk_size=config.chunk_size_tokens,
        overlap=config.overlap_tokens,
        min_tokens=config.min_chunk_tokens,
        encoding=config.tokenizer,
    )


def index_version(
    segments: list[Segment],
    *,
    organization_id: int,
    document_id: int,
    version_id: int,
    provider: EmbeddingProvider | None = None,
) -> IndexingResult:
    """Embeddings are computed before opening the transaction, so a provider failure
    leaves the version with 0 chunks (EmbeddingError propagates)."""
    chunks = build_chunker().chunk(segments)
    embeddings = embed_texts([chunk.content for chunk in chunks], provider)

    with db.connect() as conn:
        stored = replace_version_chunks(
            conn,
            organization_id=organization_id,
            document_id=document_id,
            version_id=version_id,
            chunks=chunks,
            embeddings=embeddings,
        )
    return IndexingResult(chunks=stored)
