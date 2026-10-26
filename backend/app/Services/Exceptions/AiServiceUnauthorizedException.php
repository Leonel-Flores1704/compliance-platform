<?php

namespace App\Services\Exceptions;

class AiServiceUnauthorizedException extends AiServiceException
{
    public function __construct(
        string $message = 'Invalid internal token.',
        string $errorCode = 'UNAUTHORIZED',
        int $statusCode = 401,
        ?array $responseBody = null
    ) {
        parent::__construct($message, $errorCode, $statusCode, $responseBody);
    }
}
