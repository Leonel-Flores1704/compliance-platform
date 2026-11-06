NOT_AUTHORIZED_MESSAGE = "The AI provider is not authorized."


class ProviderNotAuthorizedError(Exception):
    """An outbound AI call targets a host that is not in authorized_providers.yaml."""

    def __init__(self, host: str, purpose: str) -> None:
        super().__init__(f"Host {host!r} is not authorized for purpose {purpose!r}.")
        self.host = host
        self.purpose = purpose


class ProviderConfigurationError(RuntimeError):
    """LLM_PROVIDER or EMBEDDING_PROVIDER is not an authorized provider. Stops startup."""
