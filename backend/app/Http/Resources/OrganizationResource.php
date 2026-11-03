<?php

namespace App\Http\Resources;

use App\Models\Organization;
use App\Support\OrganizationCatalogs;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Organization
 */
class OrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $catalogs = app(OrganizationCatalogs::class);
        $option = fn (string $table, ?string $code) => ['code' => $code, 'name' => $catalogs->name($table, $code)];

        return [
            'id' => $this->id,
            'legal_name' => $this->legal_name,
            'organization_type' => $option('organization_types', $this->organization_type),
            'industry' => $option('industries', $this->industry),
            'state' => $option('mexican_states', $this->state),
            'municipality' => $this->municipality,
            'employees_count' => $this->employees_count,
            'business_activities' => array_map(
                fn (string $code) => $option('business_activities', $code),
                $this->business_activities ?? [],
            ),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
