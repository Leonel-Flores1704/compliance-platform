"""Authorized AI providers (NFR-004, BR-010). Source of truth: ADR-011."""

import os
from dataclasses import dataclass, field
from functools import lru_cache
from pathlib import Path

import yaml

DEFAULT_CONFIG_PATH = Path(__file__).resolve().parents[2] / "config" / "authorized_providers.yaml"

LLM = "llm"
EMBEDDINGS = "embeddings"


@dataclass(frozen=True)
class Provider:
    name: str
    hosts: tuple[str, ...]
    purposes: tuple[str, ...]
    enabled_by_default: bool = True
    allowed_envs: tuple[str, ...] | None = None
    requires: dict = field(default_factory=dict)

    def allowed_in(self, app_env: str) -> bool:
        return self.allowed_envs is None or app_env in self.allowed_envs


class ProviderRegistry:
    def __init__(self, providers: list[Provider]) -> None:
        self.providers = {provider.name: provider for provider in providers}

    @classmethod
    def from_yaml(cls, path: Path) -> "ProviderRegistry":
        data = yaml.safe_load(path.read_text(encoding="utf-8")) or {}
        providers = [
            Provider(
                name=item["name"],
                hosts=tuple(_normalize_host(host) for host in item.get("hosts") or []),
                purposes=tuple(item.get("purposes") or []),
                enabled_by_default=bool(item.get("enabled_by_default", True)),
                allowed_envs=tuple(item["allowed_envs"]) if "allowed_envs" in item else None,
                requires=dict(item.get("requires") or {}),
            )
            for item in data.get("providers") or []
        ]
        return cls(providers)

    def get(self, name: str) -> Provider | None:
        return self.providers.get(name)

    def is_host_allowed(self, host: str, purpose: str, *, app_env: str, selected: set[str]) -> bool:
        """A host is allowed when an active provider lists it for that purpose.

        A provider is active when it is enabled by default or selected through
        LLM_PROVIDER / EMBEDDING_PROVIDER, and allowed in the current APP_ENV.
        """
        host = _normalize_host(host)
        for provider in self.providers.values():
            active = provider.enabled_by_default or provider.name in selected
            if (
                active
                and provider.allowed_in(app_env)
                and purpose in provider.purposes
                and host in provider.hosts
            ):
                return True
        return False


@lru_cache
def _load(path: str) -> ProviderRegistry:
    return ProviderRegistry.from_yaml(Path(path))


def get_registry() -> ProviderRegistry:
    return _load(os.getenv("AUTHORIZED_PROVIDERS_PATH", str(DEFAULT_CONFIG_PATH)))


def _normalize_host(host: str) -> str:
    return host.strip().lower().rstrip(".")
