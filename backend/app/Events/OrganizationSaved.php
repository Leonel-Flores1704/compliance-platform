<?php

namespace App\Events;

use App\Models\Organization;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired every time an organization is created or updated (Organization::$dispatchesEvents).
 * Triggers the applicability evaluation of all its requirements (FR-007, ADR-021).
 */
class OrganizationSaved
{
    use Dispatchable, SerializesModels;

    public function __construct(public Organization $organization) {}
}
