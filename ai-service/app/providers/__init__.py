"""Authorized AI provider enforcement (CMP-28, NFR-004, BR-010)."""

from app.providers.client import ProviderClient
from app.providers.errors import (
    NOT_AUTHORIZED_MESSAGE,
    ProviderConfigurationError,
    ProviderNotAuthorizedError,
)
from app.providers.registry import EMBEDDINGS, LLM, ProviderRegistry, get_registry
from app.providers.settings import ProviderSettings, get_settings, validate_configured_providers

__all__ = [
    "EMBEDDINGS",
    "LLM",
    "NOT_AUTHORIZED_MESSAGE",
    "ProviderClient",
    "ProviderConfigurationError",
    "ProviderNotAuthorizedError",
    "ProviderRegistry",
    "ProviderSettings",
    "get_registry",
    "get_settings",
    "validate_configured_providers",
]
