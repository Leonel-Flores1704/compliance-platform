from fastapi.testclient import TestClient

from app.main import app
from app.security import get_expected_token

client = TestClient(app)


def test_health_success():
    token = get_expected_token()
    response = client.get("/health", headers={"X-Internal-Token": token})
    assert response.status_code == 200
    assert response.json() == {
        "status": "ok",
        "vector_store": "ok",
        "llm_provider": "ok",
    }


def test_health_unauthorized_without_token():
    response = client.get("/health")
    assert response.status_code == 401
    assert response.json() == {
        "error_code": "UNAUTHORIZED",
        "message": "Invalid internal token.",
    }


def test_health_unauthorized_with_invalid_token():
    response = client.get("/health", headers={"X-Internal-Token": "invalid-token-xyz"})
    assert response.status_code == 401
    assert response.json() == {
        "error_code": "UNAUTHORIZED",
        "message": "Invalid internal token.",
    }