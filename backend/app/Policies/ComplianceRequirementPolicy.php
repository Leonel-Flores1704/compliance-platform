<?php

namespace App\Policies;

use App\Models\ComplianceRequirement;
use App\Models\User;
use App\Support\RequirementMessages;
use Illuminate\Auth\Access\Response;

class ComplianceRequirementPolicy
{
    /**
     * ADR-005, function 3: Administrators and Compliance Staff can create requirements.
     */
    public function create(User $user): Response
    {
        return in_array($user->role, ['administrator', 'compliance_staff'], true)
            ? Response::allow()
            : Response::deny(RequirementMessages::FORBIDDEN);
    }

    /**
     * Users can only see requirements of their own organization.
     */
    public function view(User $user, ComplianceRequirement $requirement): bool
    {
        return $user->is_active !== false && $user->organization_id === $requirement->organization_id;
    }
}
