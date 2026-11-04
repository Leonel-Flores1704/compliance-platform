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
     * ADR-005, function 4: only Compliance Staff can change the compliance status.
     * A requirement of another organization is reported as not found.
     */
    public function updateStatus(User $user, ComplianceRequirement $requirement): Response
    {
        if ($user->organization_id !== $requirement->organization_id) {
            return Response::denyAsNotFound(RequirementMessages::NOT_FOUND);
        }

        return $user->role === 'compliance_staff'
            ? Response::allow()
            : Response::deny(RequirementMessages::STATUS_FORBIDDEN);
    }

    /**
     * Users can only see requirements of their own organization.
     */
    public function view(User $user, ComplianceRequirement $requirement): bool
    {
        return $user->is_active !== false && $user->organization_id === $requirement->organization_id;
    }
}
