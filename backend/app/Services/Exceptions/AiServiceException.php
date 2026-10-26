<?php

namespace App\Services\Exceptions;

use RuntimeException;
use Throwable;

class AiServiceException extends RuntimeException
{
    public function __construct(
        string $message,
        protected string $errorCode = 'UNKNOWN_ERROR',
        protected int $statusCode = 500,
        protected ?array $responseBody = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): ?array
    {
        return $this->responseBody;
    }

    public static function fromResponse(int $statusCode, array $body): self
    {
        $errorCode = $body['error_code'] ?? 'UNKNOWN_ERROR';
        $message = $body['message'] ?? 'AI service request failed.';

        return match ($errorCode) {
            'UNAUTHORIZED' => new AiServiceUnauthorizedException($message, $errorCode, $statusCode, $body),
            'VALIDATION_ERROR' => new AiServiceValidationException($message, $errorCode, $statusCode, $body),
            'UNSUPPORTED_FILE_TYPE' => new AiServiceUnsupportedFileTypeException($message, $errorCode, $statusCode, $body),
            'EXTRACTION_FAILED' => new AiServiceExtractionFailedException($message, $errorCode, $statusCode, $body),
            'OCR_FAILED' => new AiServiceOcrFailedException($message, $errorCode, $statusCode, $body),
            'EMBEDDING_FAILED' => new AiServiceEmbeddingFailedException($message, $errorCode, $statusCode, $body),
            'PROVIDER_NOT_AUTHORIZED' => new AiServiceProviderNotAuthorizedException($message, $errorCode, $statusCode, $body),
            'LLM_TIMEOUT' => new AiServiceLlmTimeoutException($message, $errorCode, $statusCode, $body),
            default => match ($statusCode) {
                401 => new AiServiceUnauthorizedException($message, $errorCode, $statusCode, $body),
                415 => new AiServiceUnsupportedFileTypeException($message, $errorCode, $statusCode, $body),
                422 => new AiServiceValidationException($message, $errorCode, $statusCode, $body),
                502 => new AiServiceEmbeddingFailedException($message, $errorCode, $statusCode, $body),
                503 => new AiServiceProviderNotAuthorizedException($message, $errorCode, $statusCode, $body),
                504 => new AiServiceLlmTimeoutException($message, $errorCode, $statusCode, $body),
                default => new self($message, $errorCode, $statusCode, $body),
            },
        };
    }
}
