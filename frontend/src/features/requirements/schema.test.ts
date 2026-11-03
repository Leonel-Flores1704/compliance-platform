import { describe, expect, it } from 'vitest'
import { displayDateToIso, isoToDisplayDate } from './dates'
import { REQUIREMENT_MESSAGES as M } from './messages'
import { requirementSchema, toRequirementPayload, type RequirementFormValues } from './schema'

const options = {
  users: [{ id: 2, name: 'Compliance Staff' }],
  categories: [
    { code: 'policy', name: 'Policy' },
    { code: 'report', name: 'Report' },
  ],
}

const valid: RequirementFormValues = {
  name: 'Psychosocial risk factors',
  description: 'Identify, analyze and prevent psychosocial risk factors at work.',
  regulation: 'NOM-035-STPS-2018',
  deadline: '31/12/2026',
  required_documents: [{ name: 'Psychosocial risk policy', category: 'policy' }],
  responsible_user_id: '2',
  status: 'pending',
}

function errorAt(values: Partial<RequirementFormValues> | Record<string, unknown>, path: string) {
  const result = requirementSchema(options).safeParse({ ...valid, ...values })
  if (result.success) return null
  return result.error.issues.find((issue) => issue.path.join('.') === path)?.message ?? null
}

describe('requirementSchema', () => {
  it('accepts a valid requirement', () => {
    expect(requirementSchema(options).safeParse(valid).success).toBe(true)
  })

  it.each<[string, Record<string, unknown>, string, string]>([
    ['name empty', { name: '' }, 'name', M.nameRequired],
    ['name shorter than 3', { name: 'AB' }, 'name', M.nameLength],
    ['name longer than 150', { name: 'N'.repeat(151) }, 'name', M.nameLength],
    ['description empty', { description: '' }, 'description', M.descriptionRequired],
    ['description shorter than 10', { description: 'Too short' }, 'description', M.descriptionLength],
    ['description longer than 2000', { description: 'D'.repeat(2001) }, 'description', M.descriptionLength],
    ['regulation empty', { regulation: '' }, 'regulation', M.regulationRequired],
    ['regulation shorter than 2', { regulation: 'N' }, 'regulation', M.regulationLength],
    ['deadline empty', { deadline: '' }, 'deadline', M.deadlineRequired],
    ['deadline in YYYY-MM-DD', { deadline: '2026-12-31' }, 'deadline', M.deadlineInvalid],
    ['deadline that does not exist', { deadline: '30/02/2026' }, 'deadline', M.deadlineInvalid],
    ['no required documents', { required_documents: [] }, 'required_documents', M.documentsRequired],
    ['required document without name', { required_documents: [{ name: ' ', category: 'policy' }] }, 'required_documents.0.name', M.documentIncomplete],
    ['required document without category', { required_documents: [{ name: 'Policy', category: '' }] }, 'required_documents.0.category', M.documentIncomplete],
    ['required document name longer than 150', { required_documents: [{ name: 'P'.repeat(151), category: 'policy' }] }, 'required_documents.0.name', M.documentNameLength],
    ['responsible user empty', { responsible_user_id: '' }, 'responsible_user_id', M.responsibleRequired],
    ['responsible user not in the options', { responsible_user_id: '99' }, 'responsible_user_id', M.responsibleInvalid],
    ['status Closed', { status: 'closed' }, 'status', M.statusInvalid],
  ])('%s', (_case, values, path, message) => {
    expect(errorAt(values, path)).toBe(message)
  })

  it('builds the API payload with the deadline in YYYY-MM-DD', () => {
    expect(toRequirementPayload({ ...valid, name: '  Padded  ' })).toEqual({
      name: 'Padded',
      description: valid.description,
      regulation: 'NOM-035-STPS-2018',
      deadline: '2026-12-31',
      required_documents: [{ name: 'Psychosocial risk policy', category: 'policy' }],
      responsible_user_id: 2,
      status: 'pending',
    })
  })
})

describe('dates', () => {
  it('converts between DD/MM/YYYY and YYYY-MM-DD', () => {
    expect(displayDateToIso('29/02/2028')).toBe('2028-02-29')
    expect(displayDateToIso('29/02/2026')).toBeNull()
    expect(displayDateToIso('1/2/2026')).toBeNull()
    expect(isoToDisplayDate('2026-12-31')).toBe('31/12/2026')
  })
})
