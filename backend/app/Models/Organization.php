<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['business_activities' => 'array', 'is_active' => 'boolean']; }
    public function users(): HasMany { return $this->hasMany(User::class); }
    public function documents(): HasMany { return $this->hasMany(Document::class); }
    public function complianceRequirements(): HasMany { return $this->hasMany(ComplianceRequirement::class); }
}
