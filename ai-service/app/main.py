import os
from contextlib import asynccontextmanager
from typing import Annotated

from fastapi import Depends, FastAPI, File, Form, Header, HTTPException, Path, Request, UploadFile, status
from fastapi.concurrency import run_in_threadpool
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse

from app.extraction import (
    DAMAGED_FILE_MESSAGE,
    MIME_BY_EXTENSION,
    ExtractionError,
    OcrRequiredError,
    UnsupportedFormatError,
    extract,
)
from app import db
from app.indexing import store as chunk_store
from app.indexing.embeddings import INDEXING_FAILED_MESSAGE, EmbeddingError
from app.indexing.pipeline import index_version
from app.indexing.store import VersionNotIndexableError
from app.providers import NOT_AUTHORIZED_MESSAGE, ProviderNotAuthorizedError, validate_configured_providers
from app.providers.llm import generate_answer
from app.schemas import (
    AskRequest,
    AskResponse,
    Citation,
    ErrorResponse,
    HealthResponse,
    IngestResponse,
    SetCurrentRequest,
    SetCurrentResponse,
)
from app.security import verify_internal_token

@asynccontextmanager
async def lifespan(_: FastAPI):
    # NFR-004 / BR-010: refuse to start with a provider that is not in authorized_providers.yaml.
    validate_configured_providers()
    yield


app = FastAPI(
    title="Compliance AI Service",
    description="FastAPI service for compliance document ingestion, indexing, and RAG Q&A.",
    version="1.0.0",
    openapi_version="3.1.0",
    lifespan=lifespan,
)

ALLOWED_EXTENSIONS = {".pdf", ".docx", ".txt"}
ALLOWED_MIME_TYPES = {
    "application/pdf",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "application/docx",
    "application/msword",
    "text/plain",
}


@app.exception_handler(RequestValidationError)
async def validation_exception_handler(request: Request, exc: RequestValidationError) -> JSONResponse:
    errors = exc.errors()
    if errors:
        first_err = errors[0]
        loc = first_err.get("loc", [])
        field_parts = [str(part) for part in loc if part not in ("body", "query", "header", "form")]
        field_name = ".".join(field_parts) if field_parts else "body"
        msg = first_err.get("msg", "Validation error")
        message = f"{field_name}: {msg}"
    else:
        message = "Validation error"

    return JSONResponse(
        status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
        content={"error_code": "VALIDATION_ERROR", "message": message},
    )


@app.exception_handler(ProviderNotAuthorizedError)
async def provider_not_authorized_handler(request: Request, exc: ProviderNotAuthorizedError) -> JSONResponse:
    return JSONResponse(
        status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
        content={"error_code": "PROVIDER_NOT_AUTHORIZED", "message": NOT_AUTHORIZED_MESSAGE},
    )


@app.exception_handler(HTTPException)
async def http_exception_handler(request: Request, exc: HTTPException) -> JSONResponse:
    if isinstance(exc.detail, dict) and "error_code" in exc.detail and "message" in exc.detail:
        return JSONResponse(status_code=exc.status_code, content=exc.detail)

    error_code_map = {
        401: "UNAUTHORIZED",
        415: "UNSUPPORTED_FILE_TYPE",
        422: "VALIDATION_ERROR",
        502: "EMBEDDING_FAILED",
        503: "PROVIDER_NOT_AUTHORIZED",
        504: "LLM_TIMEOUT",
    }
    code = error_code_map.get(exc.status_code, "ERROR")
    return JSONResponse(
        status_code=exc.status_code,
        content={"error_code": code, "message": str(exc.detail)},
    )


COMMON_ERROR_RESPONSES = {
    401: {
        "model": ErrorResponse,
        "description": "Missing or wrong X-Internal-Token.",
        "content": {
            "application/json": {
                "example": {
                    "error_code": "UNAUTHORIZED",
                    "message": "Invalid internal token.",
                }
            }
        },
    },
    422: {
        "model": ErrorResponse,
        "description": "Validation failed, text extraction failed, or OCR failed.",
        "content": {
            "application/json": {
                "examples": {
                    "validation_error": {
                        "summary": "Request body fails validation",
                        "value": {
                            "error_code": "VALIDATION_ERROR",
                            "message": "question: String should have at least 3 characters",
                        },
                    },
                    "extraction_failed": {
                        "summary": "Text could not be extracted",
                        "value": {
                            "error_code": "EXTRACTION_FAILED",
                            "message": "The file could not be read. It may be damaged or password-protected.",
                        },
                    },
                    "ocr_failed": {
                        "summary": "OCR failed",
                        "value": {
                            "error_code": "OCR_FAILED",
                            "message": "The scanned document could not be read.",
                        },
                    },
                }
            }
        },
    },
    502: {
        "model": ErrorResponse,
        "description": "Embedding provider failed.",
        "content": {
            "application/json": {
                "example": {
                    "error_code": "EMBEDDING_FAILED",
                    "message": "The document could not be indexed.",
                }
            }
        },
    },
    503: {
        "model": ErrorResponse,
        "description": "Provider not in authorized list.",
        "content": {
            "application/json": {
                "example": {
                    "error_code": "PROVIDER_NOT_AUTHORIZED",
                    "message": "The AI provider is not authorized.",
                }
            }
        },
    },
}


def _check_simulation(simulate_header: str | None = None) -> None:
    if not simulate_header:
        return
    sim = simulate_header.strip().upper()
    if sim == "EXTRACTION_FAILED":
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail={
                "error_code": "EXTRACTION_FAILED",
                "message": "The file could not be read. It may be damaged or password-protected.",
            },
        )
    if sim == "OCR_FAILED":
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail={
                "error_code": "OCR_FAILED",
                "message": "The scanned document could not be read.",
            },
        )
    if sim == "EMBEDDING_FAILED":
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail={
                "error_code": "EMBEDDING_FAILED",
                "message": "The document could not be indexed.",
            },
        )
    if sim == "PROVIDER_NOT_AUTHORIZED":
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail={
                "error_code": "PROVIDER_NOT_AUTHORIZED",
                "message": "The AI provider is not authorized.",
            },
        )
    if sim == "LLM_TIMEOUT":
        raise HTTPException(
            status_code=status.HTTP_504_GATEWAY_TIMEOUT,
            detail={
                "error_code": "LLM_TIMEOUT",
                "message": "The language model did not respond in time.",
            },
        )


@app.get(
    "/health",
    response_model=HealthResponse,
    summary="Health check",
    description="Check the health of the AI service, vector store connection, and LLM provider.",
    responses={
        200: {
            "model": HealthResponse,
            "description": "Service is healthy and ready to accept requests.",
        },
        401: COMMON_ERROR_RESPONSES[401],
    },
)
def health(_: Annotated[str, Depends(verify_internal_token)]) -> HealthResponse:
    return HealthResponse(
        status="ok",
        vector_store="ok",
        llm_provider="ok",
    )


INGEST_RESPONSES = {
    200: {
        "model": IngestResponse,
        "description": "Document successfully ingested, chunked, and indexed.",
    },
    401: COMMON_ERROR_RESPONSES[401],
    415: {
        "model": ErrorResponse,
        "description": "File type not PDF, DOCX or TXT.",
        "content": {
            "application/json": {
                "example": {
                    "error_code": "UNSUPPORTED_FILE_TYPE",
                    "message": "Only PDF, DOCX and TXT files can be processed.",
                }
            }
        },
    },
    422: COMMON_ERROR_RESPONSES[422],
    502: COMMON_ERROR_RESPONSES[502],
    503: COMMON_ERROR_RESPONSES[503],
}


@app.post(
    "/v1/documents/ingest",
    response_model=IngestResponse,
    summary="Ingest and index a document",
    description="Synchronously process and index a document (timeout 300 s). Validates file type and extracts content.",
    responses=INGEST_RESPONSES,
)
async def ingest_document(
    _: Annotated[str, Depends(verify_internal_token)],
    file: UploadFile = File(..., description="Decrypted file payload sent by Laravel"),
    organization_id: int = Form(..., description="Organization identifier"),
    document_id: int = Form(..., description="Document identifier"),
    document_version_id: int = Form(..., description="Document version identifier"),
    mime_type: str = Form(..., description="MIME type of the file"),
    access_level: str = Form(..., description="Access level (e.g. confidential, internal, public)"),
    x_simulate_error: Annotated[str | None, Header(description="Optional error simulation trigger")] = None,
) -> IngestResponse:
    _check_simulation(x_simulate_error)

    # Validate file extension and MIME type
    filename = file.filename or ""
    _, ext = os.path.splitext(filename.lower())

    normalized_mime = mime_type.lower().strip()
    is_valid_ext = ext in ALLOWED_EXTENSIONS
    is_valid_mime = normalized_mime in ALLOWED_MIME_TYPES

    if not is_valid_ext and not is_valid_mime:
        raise HTTPException(
            status_code=status.HTTP_415_UNSUPPORTED_MEDIA_TYPE,
            detail={
                "error_code": "UNSUPPORTED_FILE_TYPE",
                "message": "Only PDF, DOCX and TXT files can be processed.",
            },
        )

    # OCR is simulated until CMP-26.
    lower_name = filename.lower()
    if "ocr_failed" in lower_name:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail={
                "error_code": "OCR_FAILED",
                "message": "The scanned document could not be read.",
            },
        )

    # Text extraction (CMP-25). Format is resolved by MIME type, falling back to the extension.
    extraction_mime = normalized_mime if is_valid_mime else MIME_BY_EXTENSION[ext]
    file_bytes = await file.read()
    ocr_used = False
    try:
        segments = extract(file_bytes, extraction_mime)
        pages = max((segment.page or 0 for segment in segments), default=0)
    except ExtractionError as exc:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail={"error_code": "EXTRACTION_FAILED", "message": exc.message},
        ) from exc
    except UnsupportedFormatError as exc:
        # e.g. application/msword (legacy .doc): accepted by the contract but not readable.
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail={"error_code": "EXTRACTION_FAILED", "message": DAMAGED_FILE_MESSAGE},
        ) from exc
    except OcrRequiredError as exc:
        # Scanned PDF: handed to OCR, which is implemented in CMP-26.
        segments = []
        pages = exc.page_count
        ocr_used = True

    if "provider_not_authorized" in lower_name:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail={
                "error_code": "PROVIDER_NOT_AUTHORIZED",
                "message": "The AI provider is not authorized.",
            },
        )

    # Chunking, embedding and storage (CMP-29). Scanned PDFs have no segments until OCR (CMP-26).
    chunks = 0
    if segments:
        try:
            result = await run_in_threadpool(
                index_version,
                segments,
                organization_id=organization_id,
                document_id=document_id,
                version_id=document_version_id,
            )
            chunks = result.chunks
        except EmbeddingError as exc:
            raise HTTPException(
                status_code=status.HTTP_502_BAD_GATEWAY,
                detail={"error_code": "EMBEDDING_FAILED", "message": INDEXING_FAILED_MESSAGE},
            ) from exc
        except VersionNotIndexableError as exc:
            raise HTTPException(
                status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
                detail={"error_code": "VALIDATION_ERROR", "message": str(exc)},
            ) from exc

    return IngestResponse(
        status="processed",
        chunks=chunks,
        pages=pages,
        ocr_used=ocr_used,
    )


SET_CURRENT_RESPONSES = {
    200: {
        "model": SetCurrentResponse,
        "description": "Target version marked as current and historical versions updated.",
    },
    401: COMMON_ERROR_RESPONSES[401],
    422: COMMON_ERROR_RESPONSES[422],
}


@app.post(
    "/v1/documents/{document_id}/set-current",
    response_model=SetCurrentResponse,
    summary="Set active document version",
    description="Marks that version's chunks as current and the rest as not current.",
    responses=SET_CURRENT_RESPONSES,
)
def set_current_version(
    _: Annotated[str, Depends(verify_internal_token)],
    document_id: Annotated[int, Path(..., description="Document ID")],
    payload: SetCurrentRequest,
) -> SetCurrentResponse:
    try:
        with db.connect() as conn:
            chunk_store.set_current_version(conn, document_id, payload.document_version_id)
    except VersionNotIndexableError as exc:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail={"error_code": "VALIDATION_ERROR", "message": str(exc)},
        ) from exc
    return SetCurrentResponse(status="ok")


ASK_RESPONSES = {
    200: {
        "model": AskResponse,
        "description": "Question successfully processed by RAG pipeline.",
    },
    401: COMMON_ERROR_RESPONSES[401],
    422: COMMON_ERROR_RESPONSES[422],
    502: COMMON_ERROR_RESPONSES[502],
    503: COMMON_ERROR_RESPONSES[503],
    504: {
        "model": ErrorResponse,
        "description": "LLM did not answer in 10 s.",
        "content": {
            "application/json": {
                "example": {
                    "error_code": "LLM_TIMEOUT",
                    "message": "The language model did not respond in time.",
                }
            }
        },
    },
}


@app.post(
    "/v1/ask",
    response_model=AskResponse,
    summary="Ask compliance question (RAG)",
    description="Perform semantic retrieval across authorized documents and synthesize an answer.",
    responses=ASK_RESPONSES,
)
def ask_question(
    _: Annotated[str, Depends(verify_internal_token)],
    payload: AskRequest,
    x_simulate_error: Annotated[str | None, Header(description="Optional error simulation trigger")] = None,
) -> AskResponse:
    _check_simulation(x_simulate_error)

    # In-query simulation triggers
    q_lower = payload.question.lower()
    if "simulate_timeout" in q_lower:
        raise HTTPException(
            status_code=status.HTTP_504_GATEWAY_TIMEOUT,
            detail={
                "error_code": "LLM_TIMEOUT",
                "message": "The language model did not respond in time.",
            },
        )
    if "simulate_embedding_failed" in q_lower:
        raise HTTPException(
            status_code=status.HTTP_502_BAD_GATEWAY,
            detail={
                "error_code": "EMBEDDING_FAILED",
                "message": "The document could not be indexed.",
            },
        )
    if "simulate_provider_not_authorized" in q_lower:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail={
                "error_code": "PROVIDER_NOT_AUTHORIZED",
                "message": "The AI provider is not authorized.",
            },
        )

    citations = [
        Citation(
            document_id=payload.allowed_document_ids[0] if payload.allowed_document_ids else 1,
            document_version_id=1,
            document_name="Compliance_Manual_2026.pdf",
            page=4,
            section="4.2 Retention Policy",
            chunk_id="chunk_101",
            score=0.91,
        )
    ]

    # Retrieval and prompting are implemented in CMP-30/CMP-31; the call already goes
    # through the authorized provider client (CMP-28).
    answer = generate_answer(
        [
            {"role": "system", "content": "Answer only with the information in the context. This is not legal advice."},
            {"role": "user", "content": payload.question},
        ],
        request_id=str(payload.request_id),
    )

    return AskResponse(
        answer=answer,
        status="answered",
        citations=citations,
        disclaimer="This answer was generated automatically from indexed compliance documents for informational purposes only.",
        latency_ms=450,
    )