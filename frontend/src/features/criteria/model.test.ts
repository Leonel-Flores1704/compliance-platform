import { describe, expect, it } from 'vitest'
import { CRITERIA_MESSAGES as M } from './messages'
import { criteriaToRows, rowErrorKey, rowsToCriteria, validateRows, type ConditionRow } from './model'

const row = (patch: Partial<ConditionRow>): ConditionRow => ({ key: 1, field: '', op: '', value: '', ...patch })

describe('criteria model', () => {
  it('builds the ADR-021 JSON from the builder rows', () => {
    const rows = [
      row({ field: 'industry', op: 'in', value: ['manufacturing'] }),
      row({ field: 'employees_count', op: 'gte', value: '51' }),
      row({ field: 'employees_count', op: 'between', value: ['11', '250'] }),
    ]

    expect(rowsToCriteria(rows, 'all', true)).toEqual({
      all: [
        { field: 'industry', op: 'in', value: ['manufacturing'] },
        { field: 'employees_count', op: 'gte', value: 51 },
        { field: 'employees_count', op: 'between', value: [11, 250] },
      ],
    })
    expect(rowsToCriteria(rows.slice(0, 1), 'any', true)).toEqual({ any: [{ field: 'industry', op: 'in', value: ['manufacturing'] }] })
    expect(rowsToCriteria(rows, 'all', false)).toEqual({ all: [] })
  })

  it('loads stored criteria into rows', () => {
    const state = criteriaToRows({ any: [{ field: 'employees_count', op: 'gte', value: 51 }] })
    expect(state).toMatchObject({ useCriteria: true, match: 'any', hasNestedGroups: false })
    expect(state.rows[0]).toMatchObject({ field: 'employees_count', op: 'gte', value: '51' })
    expect(criteriaToRows({ all: [] })).toMatchObject({ useCriteria: false, rows: [] })
    expect(criteriaToRows({ all: [{ any: [] }] }).hasNestedGroups).toBe(true)
  })

  it.each<[string, ConditionRow[], string, string]>([
    ['no conditions', [], 'criteria', M.noConditions],
    ['without field', [row({ op: '', value: '' })], '0.field', M.selectField],
    ['without operator', [row({ field: 'industry' })], '0.op', M.selectOperator],
    ['without value', [row({ field: 'industry', op: 'in', value: [] })], '0.value', M.enterValue],
    ['operator not valid for the field', [row({ field: 'industry', op: 'gte', value: '5' })], '0.op', 'The operator gte cannot be used with industry.'],
    ['between with min > max', [row({ field: 'employees_count', op: 'between', value: ['300', '10'] })], 'criteria', M.invalid],
  ])('validates: %s', (_case, rows, key, message) => {
    expect(validateRows(rows, true)[key]).toBe(message)
  })

  it('does not validate rows when criteria are not used', () => {
    expect(validateRows([], false)).toEqual({})
  })

  it('maps server error keys to builder rows', () => {
    expect(rowErrorKey('criteria.all.2.op')).toBe('2.op')
    expect(rowErrorKey('criteria.any.0.value')).toBe('0.value')
    expect(rowErrorKey('criteria')).toBe('criteria')
  })
})
