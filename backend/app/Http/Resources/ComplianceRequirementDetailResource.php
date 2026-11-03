<?php

namespace App\Http\Resources;

use App\Models\ComplianceRequirement;
use App\Support\RequirementMessages;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Requirement with its required documents, used by the create and detail pages (CMP-15).
 *
 * @mixin ComplianceRequirement
 */
class ComplianceRequirementDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $categories = DB::table('document_categories')->pluck('name', 'code');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'regulation' => $this->regulation,
            'deadline' => $this->deadline?->toDateString(),
            'status' => [
                'code' => $this->status,
                'label' => RequirementMessages::STATUSES[$this->status] ?? $this->status,
            ],
            'responsible_user' => $this->responsibleUser
                ? ['id' => $this->responsibleUser->id, 'name' => $this->responsibleUser->name]
                : null,
            'required_documents' => $this->requiredDocuments
                ->sortBy('id')
                ->values()
                ->map(fn ($document) => [
                    'id' => $document->id,
                    'name' => $document->name,
                    'category' => ['code' => $document->category, 'name' => $categories[$document->category] ?? null],
                ])
                ->all(),
            'applies' => $this->applies,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
