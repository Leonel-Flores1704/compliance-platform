<?php

namespace App\Services\Exceptions;

class AiServiceProviderNotAuthorizedException extends AiServiceException
{
    /** Text shown to the user in the chat when FastAPI returns 503 PROVIDER_NOT_AUTHORIZED (CMP-28). */
    public const USER_MESSAGE = 'The AI assistant is not available right now. Please try again later.';

    public function __construct(
        string $message = 'The AI provider is not authorized.',
        string $errorCode = 'PROVIDER_NOT_AUTHORIZED',
        int $statusCode = 503,
        ?array $responseBody = null
    ) {
        parent::__construct($message, $errorCode, $statusCode, $responseBody);
    }

    public function userMessage(): string
    {
        return self::USER_MESSAGE;
    }
}
