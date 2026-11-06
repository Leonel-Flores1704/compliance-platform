# ADR-013: RAG parameters, thresholds and conflicting documents (OI-05)

| | |
|---|---|
| **Status** | Proposed, pending team approval |
| **Date** | 2026-10-19 |
| **Owner** | Fernando Cisneros Torres |
| **Jira** | CMP-03 (used by CMP-29, CMP-30, CMP-31, CMP-36) |
| **Related requirements** | AIR-002, AIR-003, AIR-005, AIR-008, NFR-009, NFR-018, OI-05 |
| **Approved by** | _pending (4 team members)_ |

## Context

The RAG pipeline needs fixed numbers for chunking, retrieval, generation and evaluation. They must fit the Groq free plan limit of **8,000 tokens per minute** (ADR-010).

## Decision

### 1. Chunking (CMP-29)

| Parameter | Value |
|---|---|
| Chunk size | **500 tokens** |
| Overlap | **75 tokens** |
| Token counter | `tiktoken` with encoding `o200k_base` |
| Split order | section → paragraph → sentence → word |
| Rule | A chunk never mixes two sections. Each chunk keeps `page_start`/`page_end` (PDF) or `section` (DOCX/TXT). |
| Minimum chunk | Chunks under 30 tokens are merged with the previous chunk of the same section. |

Why 500 and not 800: with 4 fragments of 800 tokens each, one question uses about 4,500 tokens, which allows only 1 question per minute. With 500 tokens, a question uses at most about 3,300 tokens (see section 3).

### 2. Retrieval (CMP-30)

| Parameter | Value |
|---|---|
| Candidates read from pgvector | **8** |
| Similarity threshold (cosine, bge-m3 normalized) | **0.55** (initial value, calibrate with the procedure below) |
| Fragments sent to the LLM | at most **4**, the highest-scoring candidates at or above the threshold |
| If no candidate is at or above the threshold | Return the insufficient-information response. **Do not call the LLM.** |

**Calibration procedure (run once in CMP-36, and again if the embedding model changes):**

1. Run retrieval only, without the LLM, for all questions in `ai-service/eval/seed_questions.jsonl`.
2. Record the top-1 similarity of each question.
3. Try thresholds from 0.40 to 0.75 in steps of 0.01. For each one, count answerable questions above the threshold (correct) and unanswerable questions above the threshold (wrong).
4. Choose the threshold with the best F1 score. Record it in this ADR (new revision) and in `ai-service/config/rag.yaml`.

### 3. Generation (CMP-31)

| Parameter | Value |
|---|---|
| Temperature | **0.1** |
| Max output tokens | **600** |
| Timeout | **10 s** per call |
| Retry | On 429 or 5xx: retry once with the fallback model (`gpt-oss-20b`). If that also fails: `503 LLM_RATE_LIMITED` or `502 LLM_ERROR`. |
| Answer language | Same language as the question |

Token budget per question (upper limit):

| Part | Tokens |
|---|---|
| System prompt and rules | ~450 |
| Question (max 1,000 characters) | ~250 |
| Context (4 × 500) | 2,000 |
| Answer | ≤ 600 |
| **Total** | **≤ 3,300**, so about 2 questions per minute with 8,000 TPM |

### 4. Evaluation thresholds (CMP-36)

| Metric | How it is measured | Threshold |
|---|---|---|
| Answer relevance | 2 reviewers rate each answer from 1 to 5; relevant if the average is 4 or more | **≥ 80%** of answerable questions |
| Source accuracy | The answer cites the expected document | **≥ 90%** of answerable questions |
| Citation traceability | Every citation points to a fragment that was actually retrieved (checked in logs) | **100%** of citations |
| Insufficient-information accuracy | Unanswerable questions get the insufficient-information response | **≥ 90%** of unanswerable questions |
| Document name present | Every answered response includes at least one source document name (FR-012) | **100%** |
| Latency | Real Groq call, questions run one by one | **p90 ≤ 10 s** |

### 5. Conflicting documents

1. Retrieval only uses **current versions** (`is_current = true`). An old version of the same document never conflicts with its current version.
2. If two **different current documents** give different values for the same fact (for example, two policies with different review periods), the answer must:
   - state **both** values,
   - cite **both** documents,
   - name the document with the most recent upload date first, and
   - add this sentence in the answer's language:
     - EN: **"The sources disagree on this point. Please confirm with the Compliance team."**
     - ES: **"Las fuentes no coinciden en este punto. Confírmalo con el equipo de Cumplimiento."**
3. Prompt rule to include in `answer_v1.txt`: *"If the context fragments contain contradictory information about the same fact, report every version with its citation, and do not choose one."*

## Consequences

- CMP-06: no impact (the dimension comes from ADR-010).
- CMP-29 uses 500/75 instead of the earlier proposal of 800/100.
- CMP-36 must run the calibration first, then the evaluation.
- If the team gets a paid plan later, chunk size and number of fragments can be increased in a new revision of this ADR.
