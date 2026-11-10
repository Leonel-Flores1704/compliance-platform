<?php

namespace App\Events;

use App\Models\ComplianceRequirement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after the applicability criteria of a requirement are saved (CMP-17).
 */
class CriteriaUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(public ComplianceRequirement $requirement) {}
}
