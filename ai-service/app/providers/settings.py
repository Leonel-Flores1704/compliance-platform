import logging
import os
from dataclasses import dataclass

from app.providers.errors import ProviderConfigurationError
from app.providers.registry import EMBEDDINGS, LLM, ProviderRegistry, get_registry

logger = logging.getLogger("ai.security")

DEFAULT_LLM_BASE_URLS = {
    "groq": "https://api.groq.com/openai/v1",
    "ollama": "http://ollama:11434/v1",
}


@dataclass(frozen=True)
class ProviderSettings:
    app_env: str
    llm_provider: str
    embedding_provider: str
    llm_base_url: str | None
    llm_model: str
    groq_api_key: str | None

    @property
    def selected(self) -> set[str]:
        return {self.llm_provider, self.embedding_provider}


def get_settings() -> ProviderSettings:
    """Read provider settings from the environment (ADR-010)."""
    llm_provider = os.getenv("LLM_PROVIDER", "groq").strip()
    return ProviderSettings(
        app_env=os.getenv("APP_ENV", "local").strip(),
        llm_provider=llm_provider,
        embedding_provider=os.getenv("EMBEDDING_PROVIDER", "local-embeddings").strip(),
        llm_base_url=os.getenv("LLM_BASE_URL") or DEFAULT_LLM_BASE_URLS.get(llm_provider),
        llm_model=os.getenv("LLM_MODEL", "openai/gpt-oss-120b"),
        groq_api_key=os.getenv("GROQ_API_KEY") or None,
    )


def validate_configured_providers(
    settings: ProviderSettings | None = None, registry: ProviderRegistry | None = None
) -> None:
    """Refuse to start when LLM_PROVIDER or EMBEDDING_PROVIDER is not authorized."""
    settings = settings or get_settings()
    registry = registry or get_registry()

    for name, purpose in ((settings.llm_provider, LLM), (settings.embedding_provider, EMBEDDINGS)):
        provider = registry.get(name)
        if provider is None:
            message = f"Configured provider {name} is not in authorized_providers.yaml"
        elif purpose not in provider.purposes:
            message = f"Configured provider {name} is not authorized for {purpose}"
        elif not provider.allowed_in(settings.app_env):
            message = f"Configured provider {name} is not allowed when APP_ENV={settings.app_env}"
        else:
            continue
        logger.error(message)
        raise ProviderConfigurationError(message)
