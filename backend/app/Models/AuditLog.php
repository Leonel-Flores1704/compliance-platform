<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the creation timestamp formatted in America/Mexico_City timezone.
     */
    public function displayedCreatedAt(string $format = 'Y-m-d H:i:s'): ?string
    {
        return $this->created_at?->copy()->timezone('America/Mexico_City')->format($format);
    }

    /**
     * Accessor for created_at displayed in America/Mexico_City.
     */
    public function getCreatedAtDisplayAttribute(): ?string
    {
        return $this->displayedCreatedAt();
    }

    /**
     * Accessor for created_at_mexico_city.
     */
    public function getCreatedAtMexicoCityAttribute(): ?string
    {
        return $this->displayedCreatedAt();
    }
}
