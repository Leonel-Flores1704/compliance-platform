<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuditLogException extends Exception
{
    public function __construct(
        string $message = 'The action could not be completed. Please try again.',
        int $code = 500,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function render(Request $request): JsonResponse|Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'The action could not be completed. Please try again.',
            ], 500);
        }

        return response('The action could not be completed. Please try again.', 500);
    }
}

