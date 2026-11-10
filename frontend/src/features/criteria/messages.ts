// User-facing texts of the applicability criteria (CMP-17).
// They must stay identical to backend/app/Support/CriteriaMessages.php.
export const CRITERIA_MESSAGES = {
  noConditions: 'Add at least one condition.',
  selectField: 'Select a field.',
  selectOperator: 'Select an operator.',
  enterValue: 'Enter a value.',
  invalid: 'The applicability criteria are not valid.',
  forbidden: 'You do not have permission to edit applicability criteria.',
  saved: 'Applicability criteria saved. The requirement list has been updated.',
} as const

// "The operator gte cannot be used with industry."
export const operatorNotAllowedMessage = (operator: string, field: string) =>
  `The operator ${operator} cannot be used with ${field}.`

export const CRITERIA_TEXTS = {
  everyOrganization: 'Applies to every organization',
  useCriteria: 'Use criteria',
  matchAll: 'Match all conditions',
  matchAny: 'Match any condition',
  addCondition: 'Add condition',
  remove: 'Remove',
  save: 'Save criteria',
  previewLabel: 'Applies to the current organization:',
} as const
