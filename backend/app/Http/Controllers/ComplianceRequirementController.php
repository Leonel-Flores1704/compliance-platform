<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreComplianceRequirementRequest;
use App\Http\Resources\ComplianceRequirementDetailResource;
use App\Models\AuditLog;
use App\Models\ComplianceRequirement;
use App\Support\RequirementMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ComplianceRequirementController extends Controller
{
    /**
     * Default notification periods in days (ADR-020).
     */
    private const DEFAULT_NOTIFICATION_PERIODS = [30, 15, 7, 1];

    /**
     * POST /api/compliance-requirements (FR-006).
     * The requirement, its required documents and the audit record are saved in one transaction.
     */
    public function store(StoreComplianceRequirementRequest $request): JsonResponse
    {
        $user = $request->user();

        $requirement = DB::transaction(function () use ($request, $user): ComplianceRequirement {
            $requirement = ComplianceRequirement::query()->create($request->requirementData() + [
                'organization_id' => $user->organization_id,
                'created_by' => $user->id,
                'applicability_criteria' => ['all' => []],
                'applies' => true,
                'notification_periods' => self::DEFAULT_NOTIFICATION_PERIODS,
            ]);

            $requirement->requiredDocuments()->createMany($request->requiredDocumentsData());

            AuditLog::query()->create([
                'user_id' => $user->id,
                'category' => 'functional',
                'action' => 'requirement.created',
                'resource_type' => 'compliance_requirement',
                'resource_id' => $requirement->id,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'metadata' => [
                    'name' => $requirement->name,
                    'status' => $requirement->status,
                    'required_documents' => count($request->requiredDocumentsData()),
                ],
            ]);

            return $requirement;
        });

        $requirement->load(['responsibleUser', 'requiredDocuments']);

        return (new ComplianceRequirementDetailResource($requirement))
            ->additional(['message' => RequirementMessages::CREATED])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/compliance-requirements/{complianceRequirement}.
     */
    public function show(ComplianceRequirement $complianceRequirement): ComplianceRequirementDetailResource
    {
        Gate::authorize('view', $complianceRequirement);

        return new ComplianceRequirementDetailResource($complianceRequirement->load(['responsibleUser', 'requiredDocuments']));
    }
}
