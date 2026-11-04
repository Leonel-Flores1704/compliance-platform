"""LLM answer generation through the authorized provider (ADR-010).

Prompting, grounding and fallback models are implemented in CMP-31; this module
only guarantees that the call goes to the configured, authorized provider.
"""

from app.providers.client import ProviderClient
from app.providers.registry import LLM
from app.providers.settings import ProviderSettings, get_settings

MOCK_ANSWER = (
    "According to section 4.2 of the Compliance Manual, compliance records and audit logs "
    "must be maintained for at least 7 years."
)


def generate_answer(
    messages: list[dict],
    *,
    request_id: str | None = None,
    settings: ProviderSettings | None = None,
    client: ProviderClient | None = None,
) -> str:
    settings = settings or get_settings()

    if settings.llm_provider == "mock":
        return MOCK_ANSWER

    headers = {}
    if settings.groq_api_key and settings.llm_provider == "groq":
        headers["Authorization"] = f"Bearer {settings.groq_api_key}"

    owns_client = client is None
    client = client or ProviderClient(settings=settings)
    try:
        response = client.post(
            f"{settings.llm_base_url.rstrip('/')}/chat/completions",
            purpose=LLM,
            request_id=request_id,
            headers=headers,
            json={"model": settings.llm_model, "temperature": 0.1, "messages": messages},
        )
        response.raise_for_status()
        return response.json()["choices"][0]["message"]["content"].strip()
    finally:
        if owns_client:
            client.close()
