<?php

namespace App\Services\Exceptions;

class AiServiceLlmTimeoutException extends AiServiceException
{
    public function __construct(
        string $message = 'The language model did not respond in time.',
        string $errorCode = 'LLM_TIMEOUT',
        int $statusCode = 504,
        ?array $responseBody = null
    ) {
        parent::__construct($message, $errorCode, $statusCode, $responseBody);
    }
}
