<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceRequirement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['deadline' => 'date', 'applicability_criteria' => 'array', 'applies' => 'boolean', 'notification_periods' => 'array'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function requiredDocuments(): HasMany
    {
        return $this->hasMany(RequiredDocument::class);
    }
}
