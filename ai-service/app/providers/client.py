"""The only HTTP client allowed for AI provider calls (NFR-004, BR-010, ADR-011).

Every request is checked against authorized_providers.yaml before any byte is
sent. If an AI SDK is ever used, pass it ``ProviderClient.httpx_client`` so its
requests go through the same check.
"""

import httpx

from app.providers.errors import ProviderNotAuthorizedError
from app.providers.registry import ProviderRegistry, get_registry
from app.providers.security_log import log_provider_blocked
from app.providers.settings import ProviderSettings, get_settings

PURPOSE_EXTENSION = "ai_purpose"
REQUEST_ID_EXTENSION = "ai_request_id"


class ProviderClient:
    def __init__(
        self,
        registry: ProviderRegistry | None = None,
        settings: ProviderSettings | None = None,
        timeout: float = 10.0,
        transport: httpx.BaseTransport | None = None,
    ) -> None:
        self.registry = registry or get_registry()
        self.settings = settings or get_settings()
        # The event hook runs before the request is sent, also for requests made
        # directly on httpx_client (e.g. by an injected SDK).
        self.httpx_client = httpx.Client(
            timeout=timeout,
            transport=transport,
            event_hooks={"request": [self._authorize]},
        )

    def request(
        self, method: str, url: str, *, purpose: str, request_id: str | None = None, **kwargs
    ) -> httpx.Response:
        extensions = {
            **kwargs.pop("extensions", {}),
            PURPOSE_EXTENSION: purpose,
            REQUEST_ID_EXTENSION: request_id,
        }
        return self.httpx_client.request(method, url, extensions=extensions, **kwargs)

    def post(self, url: str, *, purpose: str, request_id: str | None = None, **kwargs) -> httpx.Response:
        return self.request("POST", url, purpose=purpose, request_id=request_id, **kwargs)

    def close(self) -> None:
        self.httpx_client.close()

    def __enter__(self) -> "ProviderClient":
        return self

    def __exit__(self, *exc_info) -> None:
        self.close()

    def _authorize(self, request: httpx.Request) -> None:
        host = request.url.host
        purpose = request.extensions.get(PURPOSE_EXTENSION) or "unknown"
        request_id = request.extensions.get(REQUEST_ID_EXTENSION)
        allowed = self.registry.is_host_allowed(
            host, purpose, app_env=self.settings.app_env, selected=self.settings.selected
        )
        if not allowed:
            log_provider_blocked(host=host, purpose=purpose, request_id=request_id)
            raise ProviderNotAuthorizedError(host=host, purpose=purpose)
