import { describe, expect, it } from 'vitest'
import type { OrganizationCatalogs } from './api'
import { ORGANIZATION_MESSAGES as M } from './messages'
import { organizationSchema, toPayload, type OrganizationFormValues } from './schema'

const catalogs: OrganizationCatalogs = {
  organization_types: [{ code: 'private_company', name: 'Private company' }],
  industries: [{ code: 'manufacturing', name: 'Manufacturing' }],
  states: [{ code: '10', name: 'Durango' }],
  business_activities: ['11', '21', '22', '23', '31-33', '43', '46', '48-49', '51', '52', '53'].map((code) => ({ code, name: code })),
}

const valid: OrganizationFormValues = {
  legal_name: 'Aceros del Guadiana, S.A. de C.V.',
  organization_type: 'private_company',
  industry: 'manufacturing',
  state: '10',
  municipality: 'Durango',
  employees_count: '120',
  business_activities: ['31-33'],
}

function firstError(values: Partial<OrganizationFormValues>) {
  const result = organizationSchema(catalogs).safeParse({ ...valid, ...values })
  if (result.success) return null
  const field = Object.keys(values)[0]
  return result.error.issues.find((issue) => issue.path[0] === field)?.message ?? null
}

describe('organizationSchema', () => {
  it('accepts a valid organization', () => {
    expect(organizationSchema(catalogs).safeParse(valid).success).toBe(true)
  })

  it.each<[string, Partial<OrganizationFormValues>, string]>([
    ['legal name empty', { legal_name: '' }, M.legalNameRequired],
    ['legal name only spaces', { legal_name: '   ' }, M.legalNameRequired],
    ['legal name shorter than 3', { legal_name: 'AB' }, M.legalNameLength],
    ['legal name longer than 150', { legal_name: 'A'.repeat(151) }, M.legalNameLength],
    ['organization type not selected', { organization_type: '' }, M.organizationTypeRequired],
    ['organization type not in catalog', { organization_type: 'government_agency' }, M.organizationTypeInvalid],
    ['industry not selected', { industry: '' }, M.industryRequired],
    ['industry not in catalog', { industry: 'mining' }, M.industryInvalid],
    ['state not selected', { state: '' }, M.stateRequired],
    ['municipality empty', { municipality: '' }, M.municipalityRequired],
    ['number of employees empty', { employees_count: '' }, M.employeesRequired],
    ['number of employees decimal', { employees_count: '12.5' }, M.employeesInvalid],
    ['number of employees zero', { employees_count: '0' }, M.employeesInvalid],
    ['number of employees above 1,000,000', { employees_count: '1000001' }, M.employeesInvalid],
    ['no business activity', { business_activities: [] }, M.activitiesRequired],
    [
      'more than 10 business activities',
      { business_activities: catalogs.business_activities.map((a) => a.code) },
      M.activitiesMax,
    ],
  ])('%s', (_case, values, message) => {
    expect(firstError(values)).toBe(message)
  })

  it('accepts the limits of every range', () => {
    const result = organizationSchema(catalogs).safeParse({
      ...valid,
      legal_name: 'A'.repeat(150),
      employees_count: '1000000',
      business_activities: catalogs.business_activities.slice(0, 10).map((a) => a.code),
    })
    expect(result.success).toBe(true)
  })

  it('builds the API payload with trimmed text and a number of employees', () => {
    expect(toPayload({ ...valid, legal_name: '  ACME  ', employees_count: ' 51 ' })).toMatchObject({
      legal_name: 'ACME',
      employees_count: 51,
    })
  })
})
