"""RAG parameters from config/rag.yaml (source of truth: ADR-013)."""

import os
from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path

import yaml

DEFAULT_RAG_CONFIG_PATH = Path(__file__).resolve().parents[2] / "config" / "rag.yaml"


@dataclass(frozen=True)
class ChunkingConfig:
    chunk_size_tokens: int
    overlap_tokens: int
    min_chunk_tokens: int
    tokenizer: str


@dataclass(frozen=True)
class EmbeddingConfig:
    batch_size: int
    max_retries: int
    backoff_seconds: float


@dataclass(frozen=True)
class RagConfig:
    chunking: ChunkingConfig
    embedding: EmbeddingConfig
    hnsw_ef_search: int


@lru_cache
def _load(path: str) -> RagConfig:
    data = yaml.safe_load(Path(path).read_text(encoding="utf-8")) or {}
    chunking = data["chunking"]
    embedding = data.get("embedding") or {}
    return RagConfig(
        chunking=ChunkingConfig(
            chunk_size_tokens=int(chunking["chunk_size_tokens"]),
            overlap_tokens=int(chunking["overlap_tokens"]),
            min_chunk_tokens=int(chunking["min_chunk_tokens"]),
            tokenizer=str(chunking["tokenizer"]),
        ),
        embedding=EmbeddingConfig(
            batch_size=int(embedding.get("batch_size", 64)),
            max_retries=int(embedding.get("max_retries", 3)),
            backoff_seconds=float(embedding.get("backoff_seconds", 1.0)),
        ),
        hnsw_ef_search=int((data.get("retrieval") or {}).get("hnsw_ef_search", 40)),
    )


def get_rag_config() -> RagConfig:
    return _load(os.getenv("RAG_CONFIG_PATH", str(DEFAULT_RAG_CONFIG_PATH)))
