<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $search = trim((string) $request->query('search', ''));
        $users = User::query()
            ->where('organization_id', $request->user()->organization_id)
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(20);

        return response()->json($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);
        $validated = $request->validated();

        $user = DB::transaction(function () use ($request, $validated) {
            $user = User::query()->create([
                'organization_id' => $request->user()->organization_id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'role' => $validated['role'],
                'password' => $validated['password'],
                'must_change_password' => $validated['must_change_password'] ?? true,
                'is_active' => true,
            ]);

            $this->audit('user.created', $user);

            return $user;
        });

        return response()->json(['message' => 'User created successfully.', 'user' => $user], 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);
        $validated = $request->validated();
        $newRole = Role::from($validated['role']);

        $this->ensureActiveAdministratorRemains($user, $newRole, $user->is_active);

        DB::transaction(function () use ($user, $validated, $newRole) {
            $previousRole = $user->role;
            $attributes = collect($validated)->only(['name', 'email', 'must_change_password'])->all();
            $attributes['role'] = $newRole;
            if (! empty($validated['password'])) {
                $attributes['password'] = $validated['password'];
            }
            $user->update($attributes);

            $this->audit('user.updated', $user);
            if ($previousRole !== $newRole) {
                $this->audit('user.role_changed', $user, ['from' => $previousRole->value, 'to' => $newRole->value]);
            }
        });

        return response()->json(['message' => 'User updated successfully.', 'user' => $user->fresh()]);
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        $this->authorize('deactivate', $user);
        if ($request->user()->is($user)) {
            throw ValidationException::withMessages(['user' => 'You cannot deactivate your own account.']);
        }

        $this->ensureActiveAdministratorRemains($user, $user->role, false);

        DB::transaction(function () use ($user) {
            $user->update(['is_active' => false, 'remember_token' => null]);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $this->audit('user.deactivated', $user);
        });

        return response()->json(['message' => 'User deactivated.']);
    }

    public function activate(Request $request, User $user): JsonResponse
    {
        $this->authorize('activate', $user);

        DB::transaction(function () use ($user) {
            $user->update(['is_active' => true]);
            $this->audit('user.activated', $user);
        });

        return response()->json(['message' => 'User reactivated.']);
    }

    private function ensureActiveAdministratorRemains(User $user, Role $newRole, bool $willBeActive): void
    {
        if (! $user->is_active || $user->role !== Role::Administrator || ($newRole === Role::Administrator && $willBeActive)) {
            return;
        }

        $hasAnotherActiveAdministrator = User::query()
            ->where('organization_id', $user->organization_id)
            ->where('role', Role::Administrator->value)
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->exists();

        if (! $hasAnotherActiveAdministrator) {
            throw ValidationException::withMessages(['role' => 'The organization must have at least one active Administrator.']);
        }
    }

    private function audit(string $action, User $user, array $metadata = []): void
    {
        AuditLog::query()->create([
            'user_id' => request()->user()?->id,
            'category' => 'security',
            'action' => $action,
            'resource_type' => 'user',
            'resource_id' => $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
