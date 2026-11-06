<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        if (! Auth::attempt($credentials)) {
            return response()->json(['message' => 'The email or password is incorrect.'], 401);
        }

        $user = $request->user();

        if (! $user->is_active) {
            Auth::guard('web')->logout();

            return response()->json(['message' => 'Your account is inactive. Contact your administrator.'], 403);
        }

        // Stateful Sanctum SPA requests have a session. Token-authenticated
        // requests do not, and must not fail after a successful login.
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        $user->forceFill(['last_login_at' => now()])->save();

        AuditLog::query()->create([
            'user_id' => $user->id,
            'category' => 'security',
            'action' => 'login',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [],
            'created_at' => now(),
        ]);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
            'organization_id' => $user->organization_id,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
            'organization_id' => $user->organization_id,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(null, 204);
    }
}
