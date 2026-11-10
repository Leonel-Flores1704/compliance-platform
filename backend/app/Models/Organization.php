<?php

namespace App\Models;

use App\Events\OrganizationSaved;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $guarded = [];

    /**
     * Any create or update re-evaluates which requirements apply (FR-007, CMP-17).
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'saved' => OrganizationSaved::class,
    ];

    protected function casts(): array
    {
        return ['business_activities' => 'array', 'is_active' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function complianceRequirements(): HasMany
    {
        return $this->hasMany(ComplianceRequirement::class);
    }
}
