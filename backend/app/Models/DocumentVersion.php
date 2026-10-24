<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentVersion extends Model
{
    public const CREATED_AT = 'uploaded_at';
    public const UPDATED_AT = null;
    protected $guarded = [];
    protected function casts(): array { return ['expiration_date' => 'date', 'is_current' => 'boolean', 'uploaded_at' => 'datetime', 'queued_at' => 'datetime', 'processing_started_at' => 'datetime', 'processed_at' => 'datetime']; }
    public function document(): BelongsTo { return $this->belongsTo(Document::class); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
    public function chunks(): HasMany { return $this->hasMany(DocumentChunk::class); }
}
