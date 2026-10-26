<?php

namespace App\Services\Exceptions;

class AiServiceUnsupportedFileTypeException extends AiServiceException
{
    public function __construct(
        string $message = 'Only PDF, DOCX and TXT files can be processed.',
        string $errorCode = 'UNSUPPORTED_FILE_TYPE',
        int $statusCode = 415,
        ?array $responseBody = null
    ) {
        parent::__construct($message, $errorCode, $statusCode, $responseBody);
    }
}
