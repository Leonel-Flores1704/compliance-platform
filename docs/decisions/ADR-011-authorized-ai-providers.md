# ADR-011: Authorized AI providers (OI-02)

| | |
|---|---|
| **Status** | Proposed, pending team approval |
| **Date** | 2026-10-19 |
| **Owner** | Fernando Cisneros Torres |
| **Jira** | CMP-03 (implemented in CMP-28) |
| **Related requirements** | NFR-004, BR-010, OI-02 |
| **Approved by** | _pending (4 team members)_ |

## Context

NFR-004 and BR-010 require that documents and fragments are only sent to AI services approved by the organization. CMP-28 will block any outbound AI call to a host that is not on this list. This ADR defines the list.

## Decision

Only the following providers are authorized:

| Provider | Hosts | Purpose | Data sent | Conditions |
|---|---|---|---|---|
| **Groq** | `api.groq.com` | LLM answer generation | The user's question and up to 4 retrieved fragments per request | Zero Data Retention **enabled** in Groq Data Controls. API key only in the server `.env`. |
| **Local embeddings (bge-m3)** | none at runtime (runs inside `ai-service`) | Embeddings for fragments and questions | Nothing leaves the server | Model weights are downloaded from `huggingface.co` **only at image build time**. At runtime `HF_HUB_OFFLINE=1`. |
| **Ollama (contingency, disabled by default)** | `ollama` (internal Docker hostname only, port 11434) | LLM answer generation when Groq is unavailable | Nothing leaves the server | Enabled only with `LLM_PROVIDER=ollama`. |
| **Mock provider (tests only)** | none | Load tests (CMP-44) and unit tests | Nothing | Allowed only when `APP_ENV` is `testing` or `loadtest`. |

### Explicitly not authorized

- **Google Gemini API free tier**, because free-tier content is used to improve Google's products and may be read by human reviewers.
- Any other hosted AI API (OpenAI, Anthropic, Mistral, Cohere, OpenRouter, Hugging Face Inference API, etc.) until a new ADR approves it.
- Calling any AI service directly from the React frontend or from Laravel. All AI calls go through `ai-service` (CMP-07).

### Configuration file for CMP-28

`ai-service/config/authorized_providers.yaml`:

```yaml
# Changes to this file require a new or updated ADR approved by the team.
providers:
  - name: groq
    hosts: [api.groq.com]
    purposes: [llm]
    requires:
      zero_data_retention: true
  - name: local-embeddings
    hosts: []            # in-process, no network
    purposes: [embeddings]
  - name: ollama
    hosts: [ollama]      # internal Docker network only
    purposes: [llm]
    enabled_by_default: false
  - name: mock
    hosts: []
    purposes: [llm, embeddings]
    allowed_envs: [testing, loadtest]
```

## Consequences

- If Groq changes its data policy or limits, this ADR must be reviewed before the next demo.
- Adding a provider requires: an updated ADR, team approval in Jira, and an updated YAML file in the same Pull Request.
- CMP-28 tests must prove that a call to any host not listed above is blocked and logged.

## Sources

- https://console.groq.com/docs/your-data
- https://ai.google.dev/gemini-api/docs/pricing
