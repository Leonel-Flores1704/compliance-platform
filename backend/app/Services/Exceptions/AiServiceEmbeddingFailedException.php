<?php

namespace App\Services\Exceptions;

class AiServiceEmbeddingFailedException extends AiServiceException
{
    public function __construct(
        string $message = 'The document could not be indexed.',
        string $errorCode = 'EMBEDDING_FAILED',
        int $statusCode = 502,
        ?array $responseBody = null
    ) {
        parent::__construct($message, $errorCode, $statusCode, $responseBody);
    }
}
