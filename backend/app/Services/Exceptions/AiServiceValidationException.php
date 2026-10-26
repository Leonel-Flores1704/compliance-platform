<?php

namespace App\Services\Exceptions;

class AiServiceValidationException extends AiServiceException
{
    public function __construct(
        string $message = 'Validation error.',
        string $errorCode = 'VALIDATION_ERROR',
        int $statusCode = 422,
        ?array $responseBody = null
    ) {
        parent::__construct($message, $errorCode, $statusCode, $responseBody);
    }
}
