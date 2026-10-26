<?php

namespace App\Services\Exceptions;

class AiServiceExtractionFailedException extends AiServiceException
{
    public function __construct(
        string $message = 'The file could not be read. It may be damaged or password-protected.',
        string $errorCode = 'EXTRACTION_FAILED',
        int $statusCode = 422,
        ?array $responseBody = null
    ) {
        parent::__construct($message, $errorCode, $statusCode, $responseBody);
    }
}
