<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    public const UPDATED_AT = null;
    protected $guarded = [];
    protected function casts(): array { return ['due_date' => 'date', 'read_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function complianceRequirement(): BelongsTo { return $this->belongsTo(ComplianceRequirement::class); }
    public function documentVersion(): BelongsTo { return $this->belongsTo(DocumentVersion::class); }
}
