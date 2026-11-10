<?php

namespace App\Services\Compliance;

use App\Support\CriteriaMessages as M;

/**
 * Validates applicability criteria against ADR-021 and normalizes them for storage.
 *
 * Error keys follow the JSON path of the request, for example "criteria.all.0.field",
 * so the criteria builder can show each message under its row.
 */
class CriteriaValidator
{
    public const MAX_DEPTH = 3;

    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param  array<string, list<string>>  $catalogs  allowed codes per catalog field
     *                                                 (organization_type, industry, state, business_activities)
     */
    public function __construct(private readonly array $catalogs) {}

    /**
     * @return array<string, string> key => message (empty when valid)
     */
    public function validate(mixed $criteria, bool $useCriteria = true): array
    {
        $this->errors = [];

        if (! $useCriteria) {
            return [];
        }

        if (! is_array($criteria) || ! $this->isGroup($criteria)) {
            return ['criteria' => M::INVALID];
        }

        $this->validateGroup($criteria, 'criteria', 1);

        if ($this->errors === [] && ApplicabilityEvaluator::isEmpty($criteria)) {
            return ['criteria' => M::NO_CONDITIONS];
        }

        return $this->errors;
    }

    /**
     * Criteria as stored in compliance_requirements.applicability_criteria (ADR-021):
     * only group keys and field/op/value, employees values as integers.
     *
     * @param  array<string, mixed>|null  $criteria
     * @return array<string, mixed>
     */
    public static function normalize(?array $criteria, bool $useCriteria = true): array
    {
        if (! $useCriteria || $criteria === null) {
            return ['all' => []];
        }

        $key = array_key_exists('all', $criteria) ? 'all' : 'any';

        return [$key => array_map(function (array $item) {
            if (! array_key_exists('field', $item)) {
                return self::normalize($item);
            }

            $value = $item['value'];
            if ($item['field'] === 'employees_count') {
                $value = is_array($value) ? array_map('intval', $value) : (int) $value;
            } elseif (is_array($value)) {
                $value = array_values(array_unique(array_map('strval', $value)));
            }

            return ['field' => $item['field'], 'op' => $item['op'], 'value' => $value];
        }, array_values($criteria[$key]))];
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isGroup(array $value): bool
    {
        if (count($value) !== 1) {
            return false;
        }
        $key = array_key_first($value);

        return in_array($key, ['all', 'any'], true) && is_array($value[$key]) && array_is_list($value[$key]);
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function validateGroup(array $group, string $path, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            $this->errors['criteria'] = M::INVALID;

            return;
        }

        $key = array_key_first($group);

        foreach ($group[$key] as $index => $item) {
            $itemPath = "{$path}.{$key}.{$index}";

            if (! is_array($item)) {
                $this->errors['criteria'] = M::INVALID;

                continue;
            }

            if ($this->isGroup($item)) {
                $this->validateGroup($item, $itemPath, $depth + 1);

                continue;
            }

            $this->validateCondition($item, $itemPath);
        }
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function validateCondition(array $condition, string $path): void
    {
        $extraKeys = array_diff(array_keys($condition), ['field', 'op', 'value']);
        if ($extraKeys !== []) {
            $this->errors['criteria'] = M::INVALID;

            return;
        }

        $field = $condition['field'] ?? null;
        $op = $condition['op'] ?? null;
        $value = $condition['value'] ?? null;

        if ($field === null || $field === '') {
            $this->errors["{$path}.field"] = M::SELECT_FIELD;
        } elseif (! is_string($field) || ! array_key_exists($field, ApplicabilityEvaluator::OPERATORS)) {
            $this->errors['criteria'] = M::INVALID;

            return;
        }

        if ($op === null || $op === '') {
            $this->errors["{$path}.op"] = M::SELECT_OPERATOR;
        } elseif (! is_string($op)) {
            $this->errors['criteria'] = M::INVALID;

            return;
        } elseif (is_string($field) && $field !== '' && ! in_array($op, ApplicabilityEvaluator::OPERATORS[$field], true)) {
            $this->errors["{$path}.op"] = M::operatorNotAllowed($op, $field);
        }

        if ($value === null || $value === '' || $value === []) {
            $this->errors["{$path}.value"] = M::ENTER_VALUE;

            return;
        }

        if (isset($this->errors["{$path}.field"]) || isset($this->errors["{$path}.op"])) {
            return;
        }

        if (! $this->valueIsValid($field, $op, $value)) {
            $this->errors['criteria'] = M::INVALID;
        }
    }

    private function valueIsValid(string $field, string $op, mixed $value): bool
    {
        if ($field === 'employees_count') {
            $isCount = fn ($v) => is_int($v) && $v >= 1 && $v <= 1_000_000;

            return $op === 'between'
                ? is_array($value) && array_is_list($value) && count($value) === 2
                    && $isCount($value[0]) && $isCount($value[1]) && $value[0] <= $value[1]
                : $isCount($value);
        }

        $allowed = $this->catalogs[$field] ?? [];
        $inCatalog = fn ($v) => is_string($v) && in_array($v, $allowed, true);

        if ($op === 'eq') {
            return $inCatalog($value);
        }

        return is_array($value) && array_is_list($value) && $value !== [] && count(array_filter($value, $inCatalog)) === count($value);
    }
}
