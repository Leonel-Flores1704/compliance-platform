<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Records denied controller authorizations before Laravel renders the 403 response.
     */
    public function authorize($ability, $arguments = []): Response
    {
        try {
            return $this->authorizeForUser(request()->user(), $ability, $arguments);
        } catch (AuthorizationException $exception) {
            $request = request();
            $user = $request->user();

            AuditLog::query()->create([
                'user_id' => $user?->id,
                'category' => 'security',
                'action' => 'access.denied',
                'resource_type' => null,
                'resource_id' => null,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'route' => $request->path(),
                    'method' => $request->method(),
                    'user_role' => $user?->role?->value ?? $user?->role,
                ],
                'created_at' => now(),
            ]);

            throw $exception;
        }
    }
}
