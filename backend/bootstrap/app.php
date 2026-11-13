<?php

use App\Exceptions\AuditLogException;
use App\Http\Middleware\RequireHttps;
use App\Models\AuditLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
        $middleware->trustProxies(at: '*');
        $middleware->append(RequireHttps::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $accessDenied = function ($exception, $request) {
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

            $message = $exception->getMessage();

            return response()->json([
                'message' => $message !== '' && $message !== 'This action is unauthorized.'
                    ? $message
                    : 'You do not have permission to perform this action.',
            ], 403);
        };

        $exceptions->render(function (AuthorizationException $exception, $request) use ($accessDenied) {
            return $accessDenied($exception, $request);
        });

        $exceptions->render(function (AccessDeniedHttpException $exception, $request) use ($accessDenied) {
            return $accessDenied($exception, $request);
        });

        $exceptions->render(function (AuditLogException $exception, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'The action could not be completed. Please try again.',
                ], 500);
            }

            return response('The action could not be completed. Please try again.', 500);
        });

        $exceptions->render(function (AuthenticationException $exception, $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        $exceptions->render(function (TooManyRequestsHttpException $exception, $request) {
            if ($request->is('api/auth/login')) {
                $seconds = (int) ($exception->getHeaders()['Retry-After'] ?? 60);

                return response()->json([
                    'message' => "Too many sign-in attempts. Try again in {$seconds} seconds.",
                ], 429, $exception->getHeaders());
            }
        });
    })->create();
