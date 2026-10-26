from typing import List, Literal
from uuid import UUID
from pydantic import BaseModel, Field


class ErrorResponse(BaseModel):
    error_code: str = Field(..., description="Machine-readable error code", examples=["UNAUTHORIZED"])
    message: str = Field(..., description="Human-readable error description", examples=["Invalid internal token."])


class HealthResponse(BaseModel):
    status: str = Field(default="ok", examples=["ok"])
    vector_store: str = Field(default="ok", examples=["ok"])
    llm_provider: str = Field(default="ok", examples=["ok"])


class IngestResponse(BaseModel):
    status: str = Field(default="processed", examples=["processed"])
    chunks: int = Field(default=42, examples=[42])
    pages: int = Field(default=12, examples=[12])
    ocr_used: bool = Field(default=False, examples=[False])


class SetCurrentRequest(BaseModel):
    document_version_id: int = Field(..., description="ID of the document version to mark as current", examples=[7])


class SetCurrentResponse(BaseModel):
    status: str = Field(default="ok", examples=["ok"])


class Citation(BaseModel):
    document_id: int = Field(..., description="ID of the referenced document", examples=[1])
    document_version_id: int = Field(..., description="ID of the referenced document version", examples=[1])
    document_name: str = Field(..., description="Name of the document", examples=["Security_Policy_2026.pdf"])
    page: int = Field(..., description="Page number where snippet was found", examples=[4])
    section: str = Field(..., description="Section title or identifier", examples=["4.2 Data Retention Schedule"])
    chunk_id: str = Field(..., description="Chunk identifier", examples=["chunk_101"])
    score: float = Field(..., description="Relevance similarity score", examples=[0.89])


class AskRequest(BaseModel):
    question: str = Field(
        ...,
        min_length=3,
        max_length=1000,
        description="User question regarding compliance documentation (3-1000 chars)",
        examples=["What are the compliance requirements for data retention?"],
    )
    user_id: int = Field(..., description="User ID making the query", examples=[1])
    organization_id: int = Field(..., description="Organization ID context", examples=[1])
    allowed_document_ids: List[int] = Field(
        ...,
        description="List of document IDs the user is authorized to search",
        examples=[1, 2, 3],
    )
    request_id: UUID = Field(
        ...,
        description="UUID tracking identifier for the request",
        examples=["550e8400-e29b-41d4-a716-446655440000"],
    )


class AskResponse(BaseModel):
    answer: str = Field(
        ...,
        description="Generated answer synthesized from indexed documents",
        examples=["Data must be retained for at least 7 years in accordance with regulatory compliance standards."],
    )
    status: Literal["answered", "insufficient_information"] = Field(
        ...,
        description="Status indicating whether enough context was found",
        examples=["answered"],
    )
    citations: List[Citation] = Field(
        default_factory=list,
        description="List of document citations supporting the answer",
    )
    disclaimer: str = Field(
        ...,
        description="Legal and compliance disclaimer",
        examples=["This answer is AI-generated and for informational purposes only."],
    )
    latency_ms: int = Field(
        ...,
        description="Processing latency in milliseconds",
        examples=[450],
    )
