<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireHttps
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production') || config('app.require_https', false)) {
            if (! $request->secure()) {
                if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
                    return response()->json([
                        'message' => 'HTTPS is required.',
                    ], 426);
                }

                return redirect()->secure($request->getRequestUri(), 301);
            }
        }

        return $next($request);
    }
}
