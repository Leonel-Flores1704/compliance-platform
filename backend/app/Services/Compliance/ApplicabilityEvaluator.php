<?php

namespace App\Services\Compliance;

use App\Models\ComplianceRequirement;
use App\Models\Organization;

/**
 * Applicability engine (FR-007, ADR-021).
 *
 * Criteria format:
 *   group     := {"all": [item, ...]} | {"any": [item, ...]}
 *   item      := condition | group
 *   condition := {"field": <field>, "op": <operator>, "value": <value>}
 *
 * Empty criteria ({"all": []} or null) apply to every organization.
 * Criteria are validated by CriteriaValidator before they are stored.
 */
class ApplicabilityEvaluator
{
    /**
     * Supported fields and their operators (ADR-021).
     */
    public const OPERATORS = [
        'organization_type' => ['eq', 'in', 'not_in'],
        'industry' => ['eq', 'in', 'not_in'],
        'state' => ['eq', 'in', 'not_in'],
        'employees_count' => ['gte', 'lte', 'between'],
        'business_activities' => ['contains_any'],
    ];

    /**
     * @param  array<string, mixed>|null  $criteria
     * @param  array<string, mixed>  $organization  organization_type, industry, state, employees_count, business_activities
     */
    public function evaluate(?array $criteria, array $organization): bool
    {
        if ($criteria === null || $criteria === [] || self::isEmpty($criteria)) {
            return true;
        }

        return $this->evaluateGroup($criteria, $organization);
    }

    public function appliesTo(ComplianceRequirement $requirement, Organization $organization): bool
    {
        return $this->evaluate($requirement->applicability_criteria, self::characteristics($organization));
    }

    /**
     * @return array<string, mixed>
     */
    public static function characteristics(Organization $organization): array
    {
        return [
            'organization_type' => $organization->organization_type,
            'industry' => $organization->industry,
            'state' => $organization->state,
            'employees_count' => (int) $organization->employees_count,
            'business_activities' => array_values($organization->business_activities ?? []),
        ];
    }

    /**
     * True when the criteria contain no condition at all ({"all": []}).
     *
     * @param  array<string, mixed>  $group
     */
    public static function isEmpty(array $group): bool
    {
        $items = $group['all'] ?? $group['any'] ?? [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                return false;
            }
            if (array_key_exists('field', $item) || ! self::isEmpty($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $organization
     */
    private function evaluateGroup(array $group, array $organization): bool
    {
        if (array_key_exists('all', $group)) {
            foreach ($group['all'] as $item) {
                if (! $this->evaluateItem($item, $organization)) {
                    return false;
                }
            }

            return true;
        }

        foreach ($group['any'] ?? [] as $item) {
            if ($this->evaluateItem($item, $organization)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $organization
     */
    private function evaluateItem(array $item, array $organization): bool
    {
        if (! array_key_exists('field', $item)) {
            return $this->evaluateGroup($item, $organization);
        }

        $actual = $organization[$item['field']] ?? null;
        $expected = $item['value'];

        return match ($item['op']) {
            'eq' => $actual === $expected,
            'in' => in_array($actual, (array) $expected, true),
            'not_in' => ! in_array($actual, (array) $expected, true),
            'gte' => (int) $actual >= (int) $expected,
            'lte' => (int) $actual <= (int) $expected,
            'between' => (int) $actual >= (int) $expected[0] && (int) $actual <= (int) $expected[1],
            'contains_any' => array_intersect((array) $actual, (array) $expected) !== [],
            default => false,
        };
    }
}
