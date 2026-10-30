<?php

namespace App\Http\Controllers;

use App\Http\Resources\ComplianceRequirementResource;
use App\Models\ComplianceRequirement;

class ComplianceRequirementController extends Controller
{
    /**
     * Display the specified compliance requirement.
     */
    public function show(ComplianceRequirement $complianceRequirement): ComplianceRequirementResource
    {
        $complianceRequirement->load('responsibleUser');

        return new ComplianceRequirementResource($complianceRequirement);
    }
}
