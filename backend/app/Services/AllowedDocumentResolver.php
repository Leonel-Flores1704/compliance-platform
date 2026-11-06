<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Documents a user may receive AI answers from (BR-002, AIR-006, ADR-004, ADR-005).
 *
 * - Same organization as the user.
 * - Not archived.
 * - Current version processed (never "Processing Failed", NFR-019).
 * - General Employees only see documents with access_level = all_staff.
 *
 * CMP-30 (permission-aware retrieval) extends and tests this rule end to end.
 */
class AllowedDocumentResolver
{
    /**
     * @return list<int>
     */
    public function forUser(User $user): array
    {
        return DB::table('documents')
            ->where('documents.organization_id', $user->organization_id)
            ->where('documents.is_archived', false)
            ->when($user->role === 'general_employee', fn ($query) => $query->where('documents.access_level', 'all_staff'))
            ->whereExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('document_versions')
                ->whereColumn('document_versions.document_id', 'documents.id')
                ->where('document_versions.is_current', true)
                ->where('document_versions.status', 'processed'))
            ->orderBy('documents.id')
            ->pluck('documents.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
