# ADR-012: Vector storage (OI-03)

| | |
|---|---|
| **Status** | Proposed, pending team approval |
| **Date** | 2026-10-19 |
| **Owner** | Fernando Cisneros Torres |
| **Jira** | CMP-03 (used by CMP-06, CMP-29, CMP-30) |
| **Related requirements** | AIR-002, AIR-006, NFR-019, OI-03 |
| **Approved by** | _pending (4 team members)_ |

## Context

Fragment embeddings must be stored and searched by similarity. Every search must also filter by organization, by the documents the user may access, and by current version (CMP-30). The project already uses PostgreSQL 16.

Expected size: about 300 documents × about 20 fragments each = **6,000 vectors** × 1,024 dimensions × 4 bytes ≈ **25 MB**. That is very small.

## Options considered

| Option | Pros | Cons |
|---|---|---|
| **A. pgvector extension in the existing PostgreSQL 16** | No new service. Vectors live in the same database as documents and permissions, so the permission filter and the similarity search run in **one SQL query**. Transactions: a version's fragments are inserted all or nothing. Backups (CMP-40) already cover it. | Slower than specialized engines with millions of vectors (not our case). |
| B. Qdrant (separate container) | Fast, good filtering. | One more service to deploy, monitor and back up. Permissions would need to be copied into Qdrant payloads and kept in sync. |
| C. Chroma (separate container or embedded) | Simple Python API. | Same sync problem as B; less mature for production. |

## Decision

**pgvector in the existing PostgreSQL 16.**

| Setting | Value |
|---|---|
| Docker image | `pgvector/pgvector:pg16` |
| Extension | `CREATE EXTENSION IF NOT EXISTS vector;` (Laravel migration, CMP-06) |
| Column | `document_chunks.embedding vector(1024)` |
| Distance | Cosine (`<=>` operator). Similarity = `1 - distance`. |
| Index | `CREATE INDEX document_chunks_embedding_hnsw ON document_chunks USING hnsw (embedding vector_cosine_ops) WITH (m = 16, ef_construction = 64);` |
| Query setting | `SET hnsw.ef_search = 40;` |
| Supporting B-tree indexes | `(organization_id, document_id, is_current)` |

Reference query for CMP-30 (filters run inside the query):

```sql
SELECT id, document_id, document_version_id, content, page_start, page_end, section,
       1 - (embedding <=> :query_embedding) AS similarity
FROM document_chunks
WHERE organization_id = :organization_id
  AND document_id = ANY(:allowed_document_ids)
  AND is_current = true
ORDER BY embedding <=> :query_embedding
LIMIT :candidates;   -- 8, see ADR-013
```

## Consequences

- CMP-06 creates `document_chunks` with `vector(1024)`. If the embedding model changes, a migration must change the dimension and **all documents must be re-indexed**.
- No extra container in `docker-compose.yml` for vectors.
- Permission filtering cannot leak fragments, because there is no second store to keep in sync.
