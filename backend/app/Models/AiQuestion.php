<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiQuestion extends Model
{
    public const UPDATED_AT = null;
    protected $guarded = [];
    protected function casts(): array { return ['citations' => 'array']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function feedback(): HasMany { return $this->hasMany(AiFeedback::class); }
}
