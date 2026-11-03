<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserOptionsController extends Controller
{
    /**
     * GET /api/users/options: active users of the current organization (id + name only),
     * used for the "Responsible user" select.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $users = User::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['data' => $users->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])]);
    }
}
