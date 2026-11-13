<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\ComplianceRequirement;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ComplianceRequirementPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->staffCanManage($user);
    }

    public function view(User $user, ComplianceRequirement $requirement): Response
    {
        if ($user->organization_id !== $requirement->organization_id) {
            return Response::deny('You do not have permission to view this requirement.');
        }

        return $this->staffCanManage($user);
    }

    public function create(User $user): Response
    {
        return $this->staffCanManage($user);
    }

    public function update(User $user, ComplianceRequirement $requirement): Response
    {
        if ($user->organization_id !== $requirement->organization_id) {
            return Response::deny('You do not have permission to edit this requirement.');
        }

        return $this->staffCanManage($user);
    }

    public function updateStatus(User $user, ComplianceRequirement $requirement): Response
    {
        if ($user->organization_id !== $requirement->organization_id) {
            return Response::deny('You do not have permission to change this compliance status.');
        }

        return $user->role === Role::ComplianceStaff
            ? Response::allow()
            : Response::deny('You do not have permission to change this compliance status.');
    }

    private function staffCanManage(User $user): Response
    {
        return in_array($user->role, [Role::Administrator, Role::ComplianceStaff], true)
            ? Response::allow()
            : Response::deny('You do not have permission to manage requirements.');
    }
}
