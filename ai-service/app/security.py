import os
import secrets
from fastapi import HTTPException, Security, status
from fastapi.security import APIKeyHeader

internal_token_header = APIKeyHeader(
    name="X-Internal-Token",
    description="Internal service authentication token passed between Laravel and FastAPI",
    auto_error=False,
)


def get_expected_token() -> str:
    return os.getenv("AI_SERVICE_TOKEN", "compliance_ai_secret_token")


def verify_internal_token(token: str | None = Security(internal_token_header)) -> str:
    expected_token = get_expected_token()
    if not token or not secrets.compare_digest(token, expected_token):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail={"error_code": "UNAUTHORIZED", "message": "Invalid internal token."},
        )
    return token
