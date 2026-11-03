# ADR-010: LLM and embedding model selection (OI-01)

| | |
|---|---|
| **Status** | Proposed, pending team approval |
| **Date** | 2026-10-19 |
| **Owner** | Fernando Cisneros Torres |
| **Jira** | CMP-03 |
| **Related requirements** | AIR-002, AIR-008, NFR-009, NFR-019, OI-01 |
| **Approved by** | _Leonel De La Cruz Flores, Hector Torres Carrizales, Emilio Rangel Torres, Fernando Cisneros Torres (pending)_ |

## Context

The AI assistant (RAG) needs two models:

1. An **LLM** that writes grounded answers from retrieved fragments (CMP-31).
2. An **embedding model** that turns document fragments and questions into vectors for retrieval (CMP-29, CMP-30).

Constraints for this project:

- **Budget: USD 0.** Only free plans or self-hosted models are allowed.
- **Languages:** documents and questions can be in **Spanish and English**. A question in one language may need to find a document in the other.
- **Privacy:** documents are organizational compliance documents. Their content must not be used by a provider to train or improve its products (NFR-004, BR-010).
- **Performance:** 90% of AI answers in under 10 s (NFR-009).
- **Infrastructure:** FastAPI service in Docker (CMP-01), CPU-only servers and laptops (no GPU assumed).

## Options considered

### LLM

| Option | Cost | Privacy | Limits (free) | Notes |
|---|---|---|---|---|
| **A. Groq API, free plan: `openai/gpt-oss-120b`** | 0 | Groq does not retain inference data by default. Optional logs for up to 30 days can be turned off with Zero Data Retention (ZDR). | 30 RPM, 1,000 RPD, 8,000 TPM, 200,000 TPD per organization (base values, confirm in console) | OpenAI-compatible API, very low latency, good Spanish and English. |
| B. Groq API, free plan: `openai/gpt-oss-20b` | 0 | Same as A | Same as A | Smaller and faster than A, somewhat lower answer quality. |
| C. Google Gemini API, free tier (Flash models) | 0 | **Free-tier content is used to improve Google products** and may be read by human reviewers. Google advises against sending confidential information on the free tier. | Not published per model; set per project in AI Studio | Good quality, but fails the privacy constraint. |
| D. Local model with Ollama (e.g., an 8B open model) | 0 | Nothing leaves the server | No rate limits | On CPU-only machines an answer will likely take longer than 10 s (to be measured). Needs about 8 GB RAM extra. |

### Embeddings

| Option | Cost | Dimensions | Max input | Languages | License | Notes |
|---|---|---|---|---|---|---|
| **A. `BAAI/bge-m3` (local, sentence-transformers)** | 0 | **1024** | 8,192 tokens | 100+ (Spanish and English, cross-lingual) | MIT | Runs on CPU inside the ai-service container; no data leaves the server; no rate limits. |
| B. `intfloat/multilingual-e5-large` (local) | 0 | 1024 | 512 tokens | ~94 | MIT | Shorter input limit. |
| C. `Qwen3-Embedding-0.6B` (local) | 0 | configurable | long | 100+ | Custom license with usage restrictions | License adds risk. |
| D. Gemini embedding API, free tier | 0 | n/a | n/a | multilingual | n/a | Same privacy problem as LLM option C; rate limited. |

## Decision

| Setting | Value |
|---|---|
| LLM provider | **Groq** (free plan), with **Zero Data Retention enabled** in Data Controls |
| LLM model | **`openai/gpt-oss-120b`** |
| LLM fallback model | `openai/gpt-oss-20b` (same provider; used automatically if the main model returns 429 or 5xx) |
| LLM API base URL | `https://api.groq.com/openai/v1` (OpenAI-compatible) |
| Embedding model | **`BAAI/bge-m3`**, dense vectors only, normalized (L2) |
| **Embedding dimension** | **1024** → column `document_chunks.embedding vector(1024)` (CMP-06) |
| Embedding runtime | `sentence-transformers` on CPU inside `ai-service`; weights cached in Docker volume `hf_cache` |
| Contingency (no internet / Groq down) | Ollama container with a local model, **disabled by default**; enable with `LLM_PROVIDER=ollama` |

Environment variables (ai-service `.env`):

```
LLM_PROVIDER=groq
LLM_MODEL=openai/gpt-oss-120b
LLM_FALLBACK_MODEL=openai/gpt-oss-20b
LLM_BASE_URL=https://api.groq.com/openai/v1
GROQ_API_KEY=            # never committed; held by the team lead
EMBEDDING_MODEL=BAAI/bge-m3
EMBEDDING_DIM=1024
HF_HUB_OFFLINE=1         # after the first download, no runtime calls to huggingface.co
```

### Why

- Groq is the only free hosted option found that **does not use our data to improve its products** and offers **Zero Data Retention**. Gemini's free tier fails that test.
- `gpt-oss-120b` gives the best answer quality available on the Groq free plan. `gpt-oss-20b` has the same limits and works as a fallback.
- Running `bge-m3` locally keeps **every document fragment on our server**: only the question and the 4 selected fragments go to Groq. It handles Spanish and English and finds an English document from a Spanish question (and the other way around). It accepts up to 8,192 tokens, so chunk size is never limited by the model.

## Consequences

1. **Token budget is the main limit.** 8,000 tokens per minute and 1,000 requests per day are shared by the whole Groq organization. With the per-request budget in ADR-013 (≤ 3,300 tokens), the team can make about **2 AI questions per minute**. This is enough for development, demos and the evaluation in CMP-36 (run sequentially), but **not** for a 100-user load test.
2. **CMP-44 (load test)** must measure the AI path with `LLM_PROVIDER=mock`, a fake provider in FastAPI that returns a fixed answer after a random 2–4 s delay. Real Groq latency is measured separately in CMP-36 with sequential questions.
3. **New user-facing message** for provider rate limits (add to CMP-31 and CMP-33): when Groq answers 429 on both models, FastAPI returns `503 {"error_code": "LLM_RATE_LIMITED"}` and the chat shows: **"The AI assistant is busy right now. Please try again in a minute."**
4. **ai-service image and memory:** the `bge-m3` weights are about 2.3 GB. The ai-service container needs **at least 4 GB RAM**. The first build downloads the model once into `hf_cache`.
5. **CPU indexing time:** embedding the 18 test documents on CPU takes a few minutes. Measure it in CMP-29 and record the result here.
6. Free-plan limits can change. Before each demo, check `https://console.groq.com/settings/limits`.

## Validation checklist (before changing status to Accepted)

- [ ] Create the team Groq organization, enable **Zero Data Retention**, store the API key in the team password manager.
- [ ] Run `python scripts/compare_models.py` (in `ai-service/`) with 10 questions (5 Spanish, 5 English) from `ai-service/eval/seed_questions.jsonl` against `gpt-oss-120b` and `gpt-oss-20b`; record answer quality (1–5) and latency for each.
- [ ] Confirm the median latency of `gpt-oss-120b` stays under 10 s; if not, switch the main model to `gpt-oss-20b`.
- [ ] Run `python scripts/embedding_benchmark.py` on a team laptop; record load time, embedding time and peak RAM.
- [ ] All 4 team members approve (Jira comment on CMP-03).

## Sources

- Groq rate limits: https://console.groq.com/docs/rate-limits
- Groq data handling and Zero Data Retention: https://console.groq.com/docs/your-data
- Gemini API pricing and free-tier data use: https://ai.google.dev/gemini-api/docs/pricing
- bge-m3 model card: https://huggingface.co/BAAI/bge-m3
