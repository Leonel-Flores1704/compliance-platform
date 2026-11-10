<?php

namespace App\Support;

/**
 * User-facing texts of the applicability criteria (CMP-17).
 * The frontend uses the same texts in frontend/src/features/criteria/messages.ts.
 */
final class CriteriaMessages
{
    public const NO_CONDITIONS = 'Add at least one condition.';

    public const SELECT_FIELD = 'Select a field.';

    public const SELECT_OPERATOR = 'Select an operator.';

    public const ENTER_VALUE = 'Enter a value.';

    public const INVALID = 'The applicability criteria are not valid.';

    public const FORBIDDEN = 'You do not have permission to edit applicability criteria.';

    public const SAVED = 'Applicability criteria saved. The requirement list has been updated.';

    /**
     * "The operator gte cannot be used with industry."
     */
    public static function operatorNotAllowed(string $operator, string $field): string
    {
        return "The operator {$operator} cannot be used with {$field}.";
    }
}
