<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationMessages;
use Illuminate\Auth\Access\Response;

class OrganizationPolicy
{
    /**
     * Only Administrators can register an organization (ADR-002, ADR-005 function 2).
     */
    public function create(User $user): Response
    {
        return $user->role === 'administrator'
            ? Response::allow()
            : Response::deny(OrganizationMessages::FORBIDDEN);
    }

    /**
     * Every authenticated user can see the organization data.
     */
    public function view(User $user, Organization $organization): bool
    {
        return $user->is_active !== false;
    }
}
