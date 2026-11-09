<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequiredDocument extends Model
{
    use Auditable;

    protected $guarded = [];

    public function complianceRequirement(): BelongsTo
    {
        return $this->belongsTo(ComplianceRequirement::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
