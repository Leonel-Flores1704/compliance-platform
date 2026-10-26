<?php

namespace App\Services\Exceptions;

class AiServiceProviderNotAuthorizedException extends AiServiceException
{
    public function __construct(
        string $message = 'The AI provider is not authorized.',
        string $errorCode = 'PROVIDER_NOT_AUTHORIZED',
        int $statusCode = 503,
        ?array $responseBody = null
    ) {
        parent::__construct($message, $errorCode, $statusCode, $responseBody);
    }
}
