import ast
import io
import json
import os
import re
import subprocess
import sys
from pathlib import Path
from uuid import uuid4

import httpx
import pytest
import respx
import yaml
from fastapi.testclient import TestClient

from app.indexing import embeddings as embeddings_module
from app.indexing.embeddings import MockEmbeddingProvider
from app.main import app
from app.providers import (
    LLM,
    ProviderClient,
    ProviderConfigurationError,
    ProviderNotAuthorizedError,
    get_registry,
    get_settings,
)
from app.providers.llm import generate_answer
from app.providers.registry import DEFAULT_CONFIG_PATH
from app.security import get_expected_token
from tests import documents

AI_SERVICE_DIR = Path(__file__).resolve().parents[1]
ADR_011 = AI_SERVICE_DIR.parent / "docs" / "decisions" / "ADR-011-authorized-ai-providers.md"
AUTH_HEADERS = {"X-Internal-Token": get_expected_token()}
GROQ_COMPLETIONS = "https://api.groq.com/openai/v1/chat/completions"

client = TestClient(app)


def ask_payload(question: str = "What is the retention requirement?") -> dict:
    return {
        "question": question,
        "user_id": 1,
        "organization_id": 1,
        "allowed_document_ids": [10],
        "request_id": str(uuid4()),
    }


def read_security_log(path: Path) -> list[dict]:
    return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines()]


def use_groq(monkeypatch, base_url: str | None = None) -> None:
    monkeypatch.setenv("APP_ENV", "local")
    monkeypatch.setenv("LLM_PROVIDER", "groq")
    monkeypatch.setenv("EMBEDDING_PROVIDER", "local-embeddings")
    monkeypatch.setenv("GROQ_API_KEY", "test-key")
    if base_url:
        monkeypatch.setenv("LLM_BASE_URL", base_url)


# --- Configuration file -------------------------------------------------------


def test_yaml_contains_exactly_the_providers_approved_in_adr_011():
    adr_yaml = re.search(r"```yaml\n(.*?)```", ADR_011.read_text(encoding="utf-8"), re.S).group(1)

    assert yaml.safe_load(DEFAULT_CONFIG_PATH.read_text(encoding="utf-8")) == yaml.safe_load(adr_yaml)
    assert set(get_registry().providers) == {"groq", "local-embeddings", "ollama", "mock"}


# --- Blocked calls --------------------------------------------------------------


def test_not_allowed_host_is_blocked_before_sending_and_logged(monkeypatch, provider_env, capsys):
    use_groq(monkeypatch, base_url="https://not-allowed.example.com")
    request_id = str(uuid4())

    with respx.mock(assert_all_called=False) as router:
        route = router.route(host="not-allowed.example.com").respond(200, json={})
        with pytest.raises(ProviderNotAuthorizedError):
            generate_answer([{"role": "user", "content": "confidential fragment"}], request_id=request_id)

    assert route.call_count == 0
    assert len(router.calls) == 0

    [event] = read_security_log(provider_env)
    assert event["event"] == "provider_blocked"
    assert event["host"] == "not-allowed.example.com"
    assert event["purpose"] == "llm"
    assert event["request_id"] == request_id
    assert re.fullmatch(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z", event["timestamp"])
    assert '"event": "provider_blocked"' in capsys.readouterr().out


def test_ask_to_not_allowed_provider_returns_503(monkeypatch, provider_env):
    use_groq(monkeypatch, base_url="https://not-allowed.example.com")

    with respx.mock(assert_all_called=False) as router:
        r = client.post("/v1/ask", headers=AUTH_HEADERS, json=ask_payload())

    assert r.status_code == 503
    assert r.json() == {"error_code": "PROVIDER_NOT_AUTHORIZED", "message": "The AI provider is not authorized."}
    assert len(router.calls) == 0
    assert read_security_log(provider_env)[0]["event"] == "provider_blocked"


def test_requests_made_directly_on_the_httpx_client_are_also_checked(provider_env):
    """An SDK given ProviderClient.httpx_client cannot bypass the check."""
    with respx.mock(assert_all_called=False) as router:
        router.route(host="api.openai.com").respond(200, json={})
        with ProviderClient() as provider_client:
            with pytest.raises(ProviderNotAuthorizedError):
                provider_client.httpx_client.post("https://api.openai.com/v1/chat/completions", json={})

    assert len(router.calls) == 0
    assert read_security_log(provider_env)[0]["host"] == "api.openai.com"


def test_authorized_host_for_another_purpose_is_blocked(monkeypatch, provider_env):
    use_groq(monkeypatch)

    with respx.mock(assert_all_called=False) as router:
        router.post(GROQ_COMPLETIONS).respond(200, json={})
        with ProviderClient() as provider_client:
            with pytest.raises(ProviderNotAuthorizedError):
                provider_client.post(GROQ_COMPLETIONS, purpose="embeddings", json={"input": "fragment"})

    assert len(router.calls) == 0


def test_ollama_is_blocked_unless_selected(monkeypatch):
    use_groq(monkeypatch)
    registry, settings = get_registry(), get_settings()
    assert not registry.is_host_allowed("ollama", LLM, app_env=settings.app_env, selected=settings.selected)

    monkeypatch.setenv("LLM_PROVIDER", "ollama")
    settings = get_settings()
    assert registry.is_host_allowed("ollama", LLM, app_env=settings.app_env, selected=settings.selected)


# --- Authorized calls -------------------------------------------------------------


def test_ingest_and_ask_with_authorized_provider_only_reach_allowed_hosts(monkeypatch, provider_env, database):
    use_groq(monkeypatch)
    database.create_version()
    # bge-m3 runs in-process (no HTTP); the mock keeps the test light while the LLM goes to Groq.
    monkeypatch.setattr(embeddings_module, "get_embedding_provider", MockEmbeddingProvider)
    completion = {"choices": [{"message": {"content": "Records are kept for 7 years."}}]}

    with respx.mock(assert_all_mocked=True) as router:
        groq = router.post(GROQ_COMPLETIONS).respond(200, json=completion)

        ingest = client.post(
            "/v1/documents/ingest",
            headers=AUTH_HEADERS,
            files={"file": ("policy.pdf", io.BytesIO(documents.read("information_security_policy.pdf")), "application/pdf")},
            data={
                "organization_id": 1,
                "document_id": 10,
                "document_version_id": 1,
                "mime_type": "application/pdf",
                "access_level": "all_staff",
            },
        )
        ask = client.post("/v1/ask", headers=AUTH_HEADERS, json=ask_payload())

    assert ingest.status_code == 200
    assert ask.status_code == 200
    assert ask.json()["answer"] == "Records are kept for 7 years."
    assert groq.call_count == 1
    assert groq.calls[0].request.headers["Authorization"] == "Bearer test-key"

    settings = get_settings()
    for call in router.calls:
        assert get_registry().is_host_allowed(
            call.request.url.host, LLM, app_env=settings.app_env, selected=settings.selected
        )
    assert not provider_env.exists()  # nothing was blocked


# --- Startup validation -------------------------------------------------------------


@pytest.mark.parametrize("provider", ["gemini", "openai"])
def test_startup_fails_with_non_authorized_llm_provider(monkeypatch, caplog, provider):
    monkeypatch.setenv("LLM_PROVIDER", provider)

    with pytest.raises(ProviderConfigurationError):
        with TestClient(app):
            pass

    assert f"Configured provider {provider} is not in authorized_providers.yaml" in caplog.text


def test_startup_fails_with_non_authorized_embedding_provider(monkeypatch, caplog):
    monkeypatch.setenv("EMBEDDING_PROVIDER", "gemini-embeddings")

    with pytest.raises(ProviderConfigurationError):
        with TestClient(app):
            pass

    assert "Configured provider gemini-embeddings is not in authorized_providers.yaml" in caplog.text


def test_mock_provider_is_refused_outside_testing_and_loadtest(monkeypatch, caplog):
    monkeypatch.setenv("APP_ENV", "production")

    with pytest.raises(ProviderConfigurationError):
        with TestClient(app):
            pass

    assert "Configured provider mock is not allowed when APP_ENV=production" in caplog.text


def test_startup_succeeds_with_authorized_providers(monkeypatch):
    use_groq(monkeypatch)

    with TestClient(app) as started:
        assert started.get("/health", headers=AUTH_HEADERS).status_code == 200


def test_uvicorn_refuses_to_start_with_non_authorized_llm_provider(tmp_path):
    env = {
        **os.environ,
        "APP_ENV": "local",
        "LLM_PROVIDER": "gemini",
        "AI_SECURITY_LOG_PATH": str(tmp_path / "ai-security.log"),
    }
    result = subprocess.run(
        [sys.executable, "-m", "uvicorn", "app.main:app", "--port", "0"],
        cwd=AI_SERVICE_DIR,
        env=env,
        capture_output=True,
        text=True,
        timeout=60,
    )

    assert result.returncode != 0
    assert "Configured provider gemini is not in authorized_providers.yaml" in result.stdout + result.stderr


# --- No other HTTP client for AI calls --------------------------------------------------


def test_only_the_provider_package_opens_http_connections():
    forbidden = {"httpx", "requests", "aiohttp", "urllib.request", "openai", "groq", "anthropic"}
    offenders = []
    for path in (AI_SERVICE_DIR / "app").rglob("*.py"):
        if "providers" in path.relative_to(AI_SERVICE_DIR / "app").parts:
            continue
        for node in ast.walk(ast.parse(path.read_text(encoding="utf-8"))):
            names = []
            if isinstance(node, ast.Import):
                names = [alias.name for alias in node.names]
            elif isinstance(node, ast.ImportFrom) and node.module:
                names = [node.module]
            offenders += [f"{path.name}: {name}" for name in names if name.split(".")[0] in forbidden or name in forbidden]

    assert offenders == []


def test_httpx_transport_errors_are_not_swallowed(monkeypatch):
    use_groq(monkeypatch)

    with respx.mock() as router:
        router.post(GROQ_COMPLETIONS).mock(side_effect=httpx.ConnectTimeout("timeout"))
        with pytest.raises(httpx.ConnectTimeout):
            generate_answer([{"role": "user", "content": "hi"}])
