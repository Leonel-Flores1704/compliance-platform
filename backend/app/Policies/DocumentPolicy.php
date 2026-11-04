<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class DocumentPolicy
{
    /**
     * Determine whether the user can create/upload documents.
     */
    public function create(User $user): Response
    {
        if (in_array($user->role, ['administrator', 'compliance_staff'], true)) {
            return Response::allow();
        }

        return Response::deny('You do not have permission to upload documents.');
    }
}
