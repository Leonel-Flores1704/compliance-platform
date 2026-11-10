<?php

namespace App\Http\Controllers;

use App\Events\CriteriaUpdated;
use App\Http\Resources\ComplianceRequirementDetailResource;
use App\Models\AuditLog;
use App\Models\ComplianceRequirement;
use App\Models\Organization;
use App\Services\Compliance\ApplicabilityEvaluator;
use App\Services\Compliance\CriteriaValidator;
use App\Support\CriteriaMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ApplicabilityCriteriaController extends Controller
{
    public function __construct(
        private readonly CriteriaValidator $validator,
        private readonly ApplicabilityEvaluator $evaluator,
    ) {}

    /**
     * PUT /api/compliance-requirements/{id}/criteria
     * Body: {"use_criteria": true, "criteria": {"all": [{"field": "...", "op": "...", "value": ...}]}}
     * "use_criteria": false stores {"all": []} (applies to every organization).
     */
    public function update(Request $request, ComplianceRequirement $complianceRequirement): JsonResponse
    {
        Gate::authorize('updateCriteria', $complianceRequirement);

        [$useCriteria, $criteria] = $this->validated($request);
        $stored = CriteriaValidator::normalize($criteria, $useCriteria);
        $previous = $complianceRequirement->applicability_criteria;

        DB::transaction(function () use ($request, $complianceRequirement, $stored, $previous): void {
            $complianceRequirement->forceFill(['applicability_criteria' => $stored])->save();

            AuditLog::query()->create([
                'user_id' => $request->user()->id,
                'category' => 'functional',
                'action' => 'requirement.updated',
                'resource_type' => 'compliance_requirement',
                'resource_id' => $complianceRequirement->id,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'metadata' => ['changed_fields' => ['applicability_criteria' => ['old' => $previous, 'new' => $stored]]],
            ]);
        });

        CriteriaUpdated::dispatch($complianceRequirement);

        $complianceRequirement->refresh()->load(['responsibleUser', 'requiredDocuments']);

        return (new ComplianceRequirementDetailResource($complianceRequirement))
            ->additional(['message' => CriteriaMessages::SAVED])
            ->response();
    }

    /**
     * POST /api/compliance-requirements/criteria/preview
     * Live preview of the builder: would these criteria apply to the current organization?
     */
    public function preview(Request $request): JsonResponse
    {
        Gate::authorize('previewCriteria', ComplianceRequirement::class);

        [$useCriteria, $criteria] = $this->validated($request);
        $organization = Organization::query()->findOrFail($request->user()->organization_id);

        return response()->json([
            'applies' => $this->evaluator->evaluate(
                CriteriaValidator::normalize($criteria, $useCriteria),
                ApplicabilityEvaluator::characteristics($organization),
            ),
        ]);
    }

    /**
     * @return array{0: bool, 1: array<string, mixed>|null}
     */
    private function validated(Request $request): array
    {
        $useCriteria = $request->has('use_criteria') ? $request->boolean('use_criteria') : true;
        $criteria = $request->input('criteria');

        $errors = $this->validator->validate($criteria, $useCriteria);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$useCriteria, is_array($criteria) ? $criteria : null];
    }
}
