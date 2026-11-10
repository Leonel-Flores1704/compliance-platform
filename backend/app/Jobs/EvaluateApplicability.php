<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\ComplianceRequirement;
use App\Models\Organization;
use App\Services\Compliance\ApplicabilityEvaluator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Recalculates compliance_requirements.applies for an organization (FR-007, ADR-021).
 *
 * - Without $requirementId: every requirement of the organization (organization created or updated).
 * - With $requirementId: only that requirement (criteria saved).
 *
 * Requirements that stop applying are kept (applies = false); every change of "applies"
 * creates an audit record with the system as actor (user_id = null).
 */
class EvaluateApplicability implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const REASON_ORGANIZATION = 'organization_updated';

    public const REASON_CRITERIA = 'criteria_changed';

    public function __construct(
        public int $organizationId,
        public ?int $requirementId = null,
        public string $reason = self::REASON_ORGANIZATION,
    ) {}

    public function handle(ApplicabilityEvaluator $evaluator): void
    {
        $organization = Organization::query()->find($this->organizationId);
        if (! $organization) {
            return;
        }

        $characteristics = ApplicabilityEvaluator::characteristics($organization);

        ComplianceRequirement::query()
            ->where('organization_id', $organization->id)
            ->when($this->requirementId, fn ($query) => $query->whereKey($this->requirementId))
            ->orderBy('id')
            ->each(function (ComplianceRequirement $requirement) use ($evaluator, $characteristics): void {
                $applies = $evaluator->evaluate($requirement->applicability_criteria, $characteristics);

                if ($applies === (bool) $requirement->applies) {
                    return;
                }

                DB::transaction(function () use ($requirement, $applies): void {
                    $from = (bool) $requirement->applies;
                    $requirement->forceFill(['applies' => $applies])->saveQuietly();

                    AuditLog::query()->create([
                        'user_id' => null,
                        'category' => 'functional',
                        'action' => 'requirement.applicability_changed',
                        'resource_type' => 'compliance_requirement',
                        'resource_id' => $requirement->id,
                        'metadata' => ['from' => $from, 'to' => $applies, 'reason' => $this->reason],
                    ]);
                });
            });
    }
}
