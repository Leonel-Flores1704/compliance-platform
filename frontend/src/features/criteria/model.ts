import { CRITERIA_MESSAGES as M, operatorNotAllowedMessage } from './messages'

// Criteria format of ADR-021 (CMP-04).
export type CriteriaField = 'organization_type' | 'industry' | 'state' | 'employees_count' | 'business_activities'
export type CriteriaOperator = 'eq' | 'in' | 'not_in' | 'gte' | 'lte' | 'between' | 'contains_any'
export type Condition = { field: CriteriaField; op: CriteriaOperator; value: string | string[] | number | number[] }
export type CriteriaGroup = { all: (Condition | CriteriaGroup)[] } | { any: (Condition | CriteriaGroup)[] }
export type MatchMode = 'all' | 'any'

export const FIELD_LABELS: Record<CriteriaField, string> = {
  organization_type: 'Organization type',
  industry: 'Industry',
  state: 'State',
  employees_count: 'Number of employees',
  business_activities: 'Business activities',
}

export const FIELD_OPERATORS: Record<CriteriaField, CriteriaOperator[]> = {
  organization_type: ['eq', 'in', 'not_in'],
  industry: ['eq', 'in', 'not_in'],
  state: ['eq', 'in', 'not_in'],
  employees_count: ['gte', 'lte', 'between'],
  business_activities: ['contains_any'],
}

export const OPERATOR_LABELS: Record<CriteriaOperator, string> = {
  eq: 'equals',
  in: 'is any of',
  not_in: 'is none of',
  gte: 'greater than or equal to',
  lte: 'less than or equal to',
  between: 'between',
  contains_any: 'contains any of',
}

// Row of the builder. Values are kept as strings while editing.
export type ConditionRow = {
  key: number
  field: CriteriaField | ''
  op: CriteriaOperator | ''
  value: string | string[]
}

export type ValueKind = 'single' | 'multiple' | 'number' | 'range'

export function valueKind(field: CriteriaField | '', op: CriteriaOperator | ''): ValueKind | null {
  if (!field || !op) return null
  if (field === 'employees_count') return op === 'between' ? 'range' : 'number'
  return op === 'eq' ? 'single' : 'multiple'
}

export function emptyValue(kind: ValueKind | null): string | string[] {
  if (kind === 'multiple') return []
  if (kind === 'range') return ['', '']
  return ''
}

const isBlank = (value: string | string[]) =>
  Array.isArray(value) ? value.length === 0 || value.every((v) => v.trim() === '') : value.trim() === ''

/**
 * Same rules as backend/app/Services/Compliance/CriteriaValidator.php.
 * Keys: "criteria" for the whole criteria, "<row>.field" / "<row>.op" / "<row>.value" for a row.
 */
export function validateRows(rows: ConditionRow[], useCriteria: boolean): Record<string, string> {
  if (!useCriteria) return {}
  if (rows.length === 0) return { criteria: M.noConditions }

  const errors: Record<string, string> = {}
  rows.forEach((row, index) => {
    if (!row.field) errors[`${index}.field`] = M.selectField
    if (!row.op) errors[`${index}.op`] = M.selectOperator
    else if (row.field && !FIELD_OPERATORS[row.field].includes(row.op)) {
      errors[`${index}.op`] = operatorNotAllowedMessage(row.op, row.field)
    }
    if (isBlank(row.value)) errors[`${index}.value`] = M.enterValue
    else if (row.field === 'employees_count') {
      const numbers = (Array.isArray(row.value) ? row.value : [row.value]).map((v) => v.trim())
      const valid = numbers.every((v) => /^\d+$/.test(v) && Number(v) >= 1 && Number(v) <= 1_000_000)
      if (!valid || (row.op === 'between' && Number(numbers[0]) > Number(numbers[1]))) errors.criteria = M.invalid
    }
  })
  return errors
}

/** Builder rows -> stored JSON (ADR-021). */
export function rowsToCriteria(rows: ConditionRow[], match: MatchMode, useCriteria: boolean): CriteriaGroup {
  if (!useCriteria) return { all: [] }
  const conditions: Condition[] = rows.map((row) => {
    const field = row.field as CriteriaField
    const op = row.op as CriteriaOperator
    let value: Condition['value']
    if (field === 'employees_count') {
      value = Array.isArray(row.value) ? row.value.map((v) => Number(v)) : Number(row.value)
    } else {
      value = row.value
    }
    return { field, op, value }
  })
  return match === 'all' ? { all: conditions } : { any: conditions }
}

let rowKey = 1
export const newRow = (): ConditionRow => ({ key: rowKey++, field: '', op: '', value: '' })

/**
 * Stored JSON -> builder state. The builder edits one level of conditions;
 * nested groups (valid in ADR-021) are reported so the page can warn instead of losing them.
 */
export function criteriaToRows(criteria: CriteriaGroup | null | undefined): {
  useCriteria: boolean
  match: MatchMode
  rows: ConditionRow[]
  hasNestedGroups: boolean
} {
  const match: MatchMode = criteria && 'any' in criteria ? 'any' : 'all'
  const items = criteria ? ('any' in criteria ? criteria.any : criteria.all) : []
  const conditions = items.filter((item): item is Condition => 'field' in item)
  return {
    useCriteria: items.length > 0,
    match,
    hasNestedGroups: conditions.length !== items.length,
    rows: conditions.map((condition) => ({
      key: rowKey++,
      field: condition.field,
      op: condition.op,
      value: Array.isArray(condition.value) ? condition.value.map(String) : String(condition.value),
    })),
  }
}

/** Server error key "criteria.all.0.field" -> builder key "0.field". */
export function rowErrorKey(serverKey: string): string {
  const match = /^criteria\.(?:all|any)\.(\d+)\.(field|op|value)$/.exec(serverKey)
  return match ? `${match[1]}.${match[2]}` : 'criteria'
}
