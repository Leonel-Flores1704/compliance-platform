<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->adminOnly($user);
    }

    public function create(User $user): Response
    {
        return $this->adminOnly($user);
    }

    public function update(User $user, User $account): Response
    {
        return $this->sameOrganizationAdmin($user, $account);
    }

    public function deactivate(User $user, User $account): Response
    {
        return $this->sameOrganizationAdmin($user, $account);
    }

    public function activate(User $user, User $account): Response
    {
        return $this->sameOrganizationAdmin($user, $account);
    }

    private function adminOnly(User $user): Response
    {
        return $user->role === Role::Administrator
            ? Response::allow()
            : Response::deny('You do not have permission to manage users.');
    }

    private function sameOrganizationAdmin(User $user, User $account): Response
    {
        if ($user->organization_id !== $account->organization_id) {
            return Response::deny('You do not have permission to manage users.');
        }

        return $this->adminOnly($user);
    }
}
