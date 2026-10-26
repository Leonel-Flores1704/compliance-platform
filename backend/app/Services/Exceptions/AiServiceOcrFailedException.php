<?php

namespace App\Services\Exceptions;

class AiServiceOcrFailedException extends AiServiceException
{
    public function __construct(
        string $message = 'The scanned document could not be read.',
        string $errorCode = 'OCR_FAILED',
        int $statusCode = 422,
        ?array $responseBody = null
    ) {
        parent::__construct($message, $errorCode, $statusCode, $responseBody);
    }
}
