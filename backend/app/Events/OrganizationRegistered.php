<?php

namespace App\Events;

use App\Models\Organization;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after an organization is registered and committed.
 * CMP-17 listens to it to run the applicability evaluation (FR-007, ADR-021).
 */
class OrganizationRegistered
{
    use Dispatchable, SerializesModels;

    public function __construct(public Organization $organization) {}
}
