<?php

namespace App\Http\Resources;

use App\Models\ComplianceRequirement;
use App\Support\RequirementMessages;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Row of the requirement list (CMP-17).
 *
 * @mixin ComplianceRequirement
 */
class ComplianceRequirementListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'regulation' => $this->regulation,
            'deadline' => $this->deadline?->toDateString(),
            'status' => [
                'code' => $this->status,
                'label' => RequirementMessages::STATUSES[$this->status] ?? $this->status,
            ],
            'applies' => $this->applies,
        ];
    }
}
