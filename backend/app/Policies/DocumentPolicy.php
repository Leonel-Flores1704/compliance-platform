<?php

namespace App\Policies;

use App\Models\Document;
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

    /**
     * Determine whether the user can view the document.
     */
    public function view(User $user, Document $document): Response
    {
        if ($user->organization_id !== $document->organization_id) {
            return Response::deny('You do not have permission to view this document.');
        }

        if ($user->role === 'general_employee' && $document->access_level !== 'all_staff') {
            return Response::deny('You do not have permission to view this document.');
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can upload a new version for the document.
     */
    public function createVersion(User $user, Document $document): Response
    {
        if ($user->organization_id !== $document->organization_id) {
            return Response::deny('You do not have permission to upload a new version.');
        }

        if (! in_array($user->role, ['administrator', 'compliance_staff'], true)) {
            return Response::deny('You do not have permission to upload documents.');
        }

        if ($document->is_archived) {
            return Response::deny('Cannot upload a new version to an archived document.');
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can download the document.
     */
    public function download(User $user, Document $document): Response
    {
        if ($user->organization_id !== $document->organization_id) {
            return Response::deny('You do not have permission to download this document.');
        }

        if ($user->role === 'general_employee' && $document->access_level !== 'all_staff') {
            return Response::deny('You do not have permission to download this document.');
        }

        return Response::allow();
    }
}
