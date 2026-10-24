<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChunk extends Model
{
    public const UPDATED_AT = null;
    protected $guarded = [];
    protected function casts(): array { return ['is_current' => 'boolean']; }
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function document(): BelongsTo { return $this->belongsTo(Document::class); }
    public function documentVersion(): BelongsTo { return $this->belongsTo(DocumentVersion::class); }
}
