<?php

use App\Services\Compliance\ApplicabilityEvaluator;
use App\Services\Compliance\CriteriaValidator;
use App\Support\CriteriaMessages as M;

// Demo organization of the acceptance criteria: Manufacturing, 120 employees.
function demoOrganization(array $overrides = []): array
{
    return array_merge([
        'organization_type' => 'private_company',
        'industry' => 'manufacturing',
        'state' => '10',
        'employees_count' => 120,
        'business_activities' => ['31-33', '43'],
    ], $overrides);
}

function condition(string $field, string $op, mixed $value): array
{
    return ['all' => [['field' => $field, 'op' => $op, 'value' => $value]]];
}

describe('ApplicabilityEvaluator: one matching and one non-matching case per characteristic', function (): void {
    it('evaluates a single condition', function (string $field, string $op, mixed $value, bool $expected): void {
        expect((new ApplicabilityEvaluator)->evaluate(condition($field, $op, $value), demoOrganization()))->toBe($expected);
    })->with([
        // organization_type
        'organization_type eq private_company (match)' => ['organization_type', 'eq', 'private_company', true],
        'organization_type eq cooperative (no match)' => ['organization_type', 'eq', 'cooperative', false],
        'organization_type in [private_company, cooperative] (match)' => ['organization_type', 'in', ['private_company', 'cooperative'], true],
        'organization_type not_in [private_company] (no match)' => ['organization_type', 'not_in', ['private_company'], false],
        // industry
        'industry in [manufacturing] (match)' => ['industry', 'in', ['manufacturing'], true],
        'industry in [retail, healthcare] (no match)' => ['industry', 'in', ['retail', 'healthcare'], false],
        'industry eq manufacturing (match)' => ['industry', 'eq', 'manufacturing', true],
        'industry not_in [retail] (match)' => ['industry', 'not_in', ['retail'], true],
        // state
        'state eq 10 Durango (match)' => ['state', 'eq', '10', true],
        'state in [05, 19] (no match)' => ['state', 'in', ['05', '19'], false],
        'state not_in [09] (match)' => ['state', 'not_in', ['09'], true],
        'state not_in [10] (no match)' => ['state', 'not_in', ['10'], false],
        // employees_count
        'employees_count gte 51 (match)' => ['employees_count', 'gte', 51, true],
        'employees_count gte 251 (no match)' => ['employees_count', 'gte', 251, false],
        'employees_count lte 120 (match, limit)' => ['employees_count', 'lte', 120, true],
        'employees_count lte 119 (no match)' => ['employees_count', 'lte', 119, false],
        'employees_count between [11, 250] (match)' => ['employees_count', 'between', [11, 250], true],
        'employees_count between [121, 250] (no match)' => ['employees_count', 'between', [121, 250], false],
        // business_activities
        'business_activities contains_any [31-33, 21] (match)' => ['business_activities', 'contains_any', ['31-33', '21'], true],
        'business_activities contains_any [62] (no match)' => ['business_activities', 'contains_any', ['62'], false],
    ]);
});

describe('ApplicabilityEvaluator: groups', function (): void {
    $evaluator = new ApplicabilityEvaluator;

    it('applies empty criteria to every organization', function () use ($evaluator): void {
        expect($evaluator->evaluate(['all' => []], demoOrganization()))->toBeTrue()
            ->and($evaluator->evaluate(null, demoOrganization()))->toBeTrue()
            ->and($evaluator->evaluate([], demoOrganization()))->toBeTrue();
    });

    it('requires every condition in an "all" group (acceptance criteria example)', function () use ($evaluator): void {
        $applies = ['all' => [
            ['field' => 'industry', 'op' => 'in', 'value' => ['manufacturing']],
            ['field' => 'employees_count', 'op' => 'gte', 'value' => 51],
        ]];
        $doesNotApply = ['all' => [
            ['field' => 'industry', 'op' => 'in', 'value' => ['manufacturing']],
            ['field' => 'employees_count', 'op' => 'gte', 'value' => 251],
        ]];

        expect($evaluator->evaluate($applies, demoOrganization()))->toBeTrue()
            ->and($evaluator->evaluate($doesNotApply, demoOrganization()))->toBeFalse()
            ->and($evaluator->evaluate($doesNotApply, demoOrganization(['employees_count' => 300])))->toBeTrue();
    });

    it('needs only one condition in an "any" group', function () use ($evaluator): void {
        $criteria = ['any' => [
            ['field' => 'industry', 'op' => 'eq', 'value' => 'healthcare'],
            ['field' => 'employees_count', 'op' => 'gte', 'value' => 100],
        ]];

        expect($evaluator->evaluate($criteria, demoOrganization()))->toBeTrue()
            ->and($evaluator->evaluate($criteria, demoOrganization(['employees_count' => 20])))->toBeFalse();
    });

    it('evaluates nested groups (ADR-021 example 4)', function () use ($evaluator): void {
        $criteria = ['any' => [
            ['field' => 'industry', 'op' => 'eq', 'value' => 'healthcare'],
            ['all' => [
                ['field' => 'business_activities', 'op' => 'contains_any', 'value' => ['62']],
                ['field' => 'employees_count', 'op' => 'gte', 'value' => 101],
            ]],
        ]];

        expect($evaluator->evaluate($criteria, demoOrganization()))->toBeFalse()
            ->and($evaluator->evaluate($criteria, demoOrganization(['business_activities' => ['62']])))->toBeTrue()
            ->and($evaluator->evaluate($criteria, demoOrganization(['industry' => 'healthcare', 'employees_count' => 5])))->toBeTrue();
    });
});

describe('CriteriaValidator', function (): void {
    $validator = fn () => new CriteriaValidator([
        'organization_type' => ['private_company', 'public_institution', 'non_profit_organization', 'cooperative'],
        'industry' => ['manufacturing', 'retail', 'healthcare'],
        'state' => ['05', '10', '19'],
        'business_activities' => ['21', '31-33', '43', '62'],
    ]);

    it('accepts every ADR-021 example', function () use ($validator): void {
        foreach ([
            ['all' => [['field' => 'industry', 'op' => 'in', 'value' => ['manufacturing']], ['field' => 'employees_count', 'op' => 'gte', 'value' => 51]]],
            ['all' => [['field' => 'state', 'op' => 'in', 'value' => ['10', '05']], ['field' => 'business_activities', 'op' => 'contains_any', 'value' => ['21']]]],
            ['all' => [['field' => 'organization_type', 'op' => 'in', 'value' => ['private_company', 'cooperative']], ['field' => 'employees_count', 'op' => 'between', 'value' => [11, 250]], ['field' => 'industry', 'op' => 'not_in', 'value' => ['retail']]]],
            ['any' => [['field' => 'industry', 'op' => 'eq', 'value' => 'healthcare'], ['all' => [['field' => 'business_activities', 'op' => 'contains_any', 'value' => ['62']], ['field' => 'employees_count', 'op' => 'gte', 'value' => 101]]]]],
        ] as $criteria) {
            expect($validator()->validate($criteria))->toBe([]);
        }
    });

    it('returns the exact message for each case', function (mixed $criteria, string $key, string $message, bool $useCriteria = true) use ($validator): void {
        expect($validator()->validate($criteria, $useCriteria))->toHaveKey($key, $message);
    })->with([
        'no conditions' => [['all' => []], 'criteria', M::NO_CONDITIONS],
        'only empty nested groups' => [['any' => [['all' => []]]], 'criteria', M::NO_CONDITIONS],
        'condition without field' => [condition('', 'eq', 'x'), 'criteria.all.0.field', M::SELECT_FIELD],
        'condition without operator' => [['all' => [['field' => 'industry', 'value' => ['retail']]]], 'criteria.all.0.op', M::SELECT_OPERATOR],
        'condition without value' => [condition('industry', 'in', []), 'criteria.all.0.value', M::ENTER_VALUE],
        'employees without value' => [condition('employees_count', 'gte', null), 'criteria.all.0.value', M::ENTER_VALUE],
        'operator not valid for the field' => [condition('industry', 'gte', 5), 'criteria.all.0.op', 'The operator gte cannot be used with industry.'],
        'contains_any with employees_count' => [condition('employees_count', 'contains_any', [1]), 'criteria.all.0.op', 'The operator contains_any cannot be used with employees_count.'],
        'not a group' => [['field' => 'industry'], 'criteria', M::INVALID],
        'string instead of object' => ['{"all": [', 'criteria', M::INVALID],
        'missing criteria' => [null, 'criteria', M::INVALID],
        'unknown group key' => [['every' => []], 'criteria', M::INVALID],
        'unknown field' => [condition('color', 'eq', 'red'), 'criteria', M::INVALID],
        'unknown catalog code' => [condition('industry', 'in', ['mining']), 'criteria', M::INVALID],
        'between with min > max' => [condition('employees_count', 'between', [300, 10]), 'criteria', M::INVALID],
        'employees as text' => [condition('employees_count', 'gte', '51'), 'criteria', M::INVALID],
        'extra keys in a condition' => [['all' => [['field' => 'industry', 'op' => 'eq', 'value' => 'retail', 'x' => 1]]], 'criteria', M::INVALID],
        'deeper than 3 levels' => [['all' => [['all' => [['all' => [['all' => [condition('industry', 'eq', 'retail')['all'][0]]]]]]]]], 'criteria', M::INVALID],
    ]);

    it('accepts "use criteria" off without conditions', function () use ($validator): void {
        expect($validator()->validate(null, useCriteria: false))->toBe([]);
    });

    it('normalizes criteria to the ADR-021 format', function (): void {
        expect(CriteriaValidator::normalize(['all' => [['field' => 'industry', 'op' => 'in', 'value' => ['manufacturing', 'manufacturing']], ['field' => 'employees_count', 'op' => 'gte', 'value' => 51]]]))
            ->toBe(['all' => [['field' => 'industry', 'op' => 'in', 'value' => ['manufacturing']], ['field' => 'employees_count', 'op' => 'gte', 'value' => 51]]])
            ->and(CriteriaValidator::normalize(['any' => [['field' => 'industry', 'op' => 'eq', 'value' => 'retail']]], useCriteria: false))
            ->toBe(['all' => []]);
    });
});
