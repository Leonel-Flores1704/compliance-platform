<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentVersion extends Model
{
    use Auditable;

    public const CREATED_AT = 'uploaded_at';

    public const UPDATED_AT = null;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_PROCESSING_FAILED = 'processing_failed';

    /** Status badge shown in the document detail (FR-003). */
    public const STATUS_LABELS = [
        self::STATUS_QUEUED => 'Queued',
        self::STATUS_PROCESSING => 'Processing',
        self::STATUS_PROCESSED => 'Processed',
        self::STATUS_PROCESSING_FAILED => 'Processing Failed',
    ];

    public const MESSAGE_DAMAGED_FILE = 'The file could not be read. It may be damaged or password-protected.';

    public const MESSAGE_NO_TEXT = 'No text could be extracted from this document.';

    protected $guarded = [];

    /**
     * Keep failure_reason limited to the exact user-facing messages defined in CMP-25.
     */
    public static function extractionFailureMessage(string $message): string
    {
        return in_array($message, [self::MESSAGE_DAMAGED_FILE, self::MESSAGE_NO_TEXT], true)
            ? $message
            : self::MESSAGE_DAMAGED_FILE;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    protected function casts(): array
    {
        return ['expiration_date' => 'date', 'is_current' => 'boolean', 'uploaded_at' => 'datetime', 'queued_at' => 'datetime', 'processing_started_at' => 'datetime', 'processed_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }
}
