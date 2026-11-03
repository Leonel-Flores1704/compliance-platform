import pytest


@pytest.fixture(autouse=True)
def provider_env(monkeypatch, tmp_path):
    """Tests use the mock LLM provider (allowed only in APP_ENV=testing, ADR-011)."""
    monkeypatch.setenv("APP_ENV", "testing")
    monkeypatch.setenv("LLM_PROVIDER", "mock")
    monkeypatch.setenv("EMBEDDING_PROVIDER", "local-embeddings")
    monkeypatch.delenv("LLM_BASE_URL", raising=False)
    monkeypatch.delenv("GROQ_API_KEY", raising=False)
    monkeypatch.setenv("AI_SECURITY_LOG_PATH", str(tmp_path / "logs" / "ai-security.log"))
    return tmp_path / "logs" / "ai-security.log"
