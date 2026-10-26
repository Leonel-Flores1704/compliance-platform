<?php

namespace App\Services;

use App\Services\Exceptions\AiServiceException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

class AiServiceClient
{
    protected string $baseUrl;

    protected string $token;

    protected int $defaultTimeout;

    public function __construct(
        ?string $baseUrl = null,
        ?string $token = null,
        int $defaultTimeout = 30
    ) {
        $this->baseUrl = rtrim($baseUrl ?? config('services.ai.url', 'http://ai-service:8001'), '/');
        $this->token = (string) ($token ?? config('services.ai.token', ''));
        $this->defaultTimeout = $defaultTimeout;
    }

    /**
     * Check the operational health of the AI service.
     *
     * @return array{status: string, vector_store: string, llm_provider: string}
     */
    public function health(): array
    {
        $response = $this->client(timeout: 5)
            ->get("{$this->baseUrl}/health");

        return $this->handleResponse($response);
    }

    /**
     * Synchronously ingest, extract, chunk, and index a document.
     *
     * @param  string|resource|UploadedFile  $file
     * @return array{status: string, chunks: int, pages: int, ocr_used: bool}
     */
    public function ingest(
        mixed $file,
        int $organizationId,
        int $documentId,
        int $documentVersionId,
        string $mimeType,
        string $accessLevel,
        ?string $filename = null
    ): array {
        $client = $this->client(timeout: 300);

        if ($file instanceof UploadedFile) {
            $contents = fopen($file->getRealPath(), 'r');
            $filename = $filename ?? $file->getClientOriginalName();
        } elseif (is_resource($file)) {
            $contents = $file;
            $filename = $filename ?? 'document.pdf';
        } else {
            // Raw binary/string content or file path
            if (@file_exists($file) && is_file($file)) {
                $contents = fopen($file, 'r');
                $filename = $filename ?? basename($file);
            } else {
                $contents = (string) $file;
                $filename = $filename ?? 'document.pdf';
            }
        }

        $response = $client->attach('file', $contents, $filename)
            ->post("{$this->baseUrl}/v1/documents/ingest", [
                'organization_id' => $organizationId,
                'document_id' => $documentId,
                'document_version_id' => $documentVersionId,
                'mime_type' => $mimeType,
                'access_level' => $accessLevel,
            ]);

        return $this->handleResponse($response);
    }

    /**
     * Mark a specific version of a document as the active/current indexed version.
     *
     * @return array{status: string}
     */
    public function setCurrent(int $documentId, int $documentVersionId): array
    {
        $response = $this->client()
            ->post("{$this->baseUrl}/v1/documents/{$documentId}/set-current", [
                'document_version_id' => $documentVersionId,
            ]);

        return $this->handleResponse($response);
    }

    /**
     * Ask a compliance question across authorized documents via RAG.
     *
     * @param  array<int>  $allowedDocumentIds
     * @return array{answer: string, status: string, citations: array, disclaimer: string, latency_ms: int}
     */
    public function ask(
        string $question,
        int $userId,
        int $organizationId,
        array $allowedDocumentIds,
        string $requestId
    ): array {
        $response = $this->client(timeout: 15)
            ->post("{$this->baseUrl}/v1/ask", [
                'question' => $question,
                'user_id' => $userId,
                'organization_id' => $organizationId,
                'allowed_document_ids' => array_values($allowedDocumentIds),
                'request_id' => $requestId,
            ]);

        return $this->handleResponse($response);
    }

    /**
     * Build the pre-configured HTTP client with internal authentication header.
     */
    protected function client(?int $timeout = null): PendingRequest
    {
        return Http::timeout($timeout ?? $this->defaultTimeout)
            ->withHeaders([
                'X-Internal-Token' => $this->token,
                'Accept' => 'application/json',
            ]);
    }

    /**
     * Handle HTTP response, mapping non-2xx status codes and error payloads to typed exceptions.
     *
     * @throws AiServiceException
     */
    protected function handleResponse(Response $response): array
    {
        if ($response->successful()) {
            return (array) $response->json();
        }

        $body = $response->json();
        if (! is_array($body)) {
            $body = [
                'error_code' => 'HTTP_ERROR_'.$response->status(),
                'message' => $response->body() ?: 'AI service HTTP error '.$response->status(),
            ];
        }

        throw AiServiceException::fromResponse($response->status(), $body);
    }
}
