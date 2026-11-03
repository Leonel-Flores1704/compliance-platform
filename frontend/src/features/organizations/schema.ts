import { z } from 'zod'
import type { OrganizationCatalogs, OrganizationPayload } from './api'
import { ORGANIZATION_MESSAGES as M } from './messages'

// Same rules as backend/app/Http/Requests/StoreOrganizationRequest.php (the source of truth).
// Catalog codes are checked against the catalogs returned by the API.
export function organizationSchema(catalogs?: OrganizationCatalogs) {
  const inCatalog = (list: { code: string }[] | undefined) => (value: string) =>
    !list || value === '' || list.some((option) => option.code === value)

  return z.object({
    legal_name: z.string().trim().min(1, M.legalNameRequired).min(3, M.legalNameLength).max(150, M.legalNameLength),
    organization_type: z
      .string()
      .min(1, M.organizationTypeRequired)
      .refine(inCatalog(catalogs?.organization_types), M.organizationTypeInvalid),
    industry: z.string().min(1, M.industryRequired).refine(inCatalog(catalogs?.industries), M.industryInvalid),
    state: z.string().min(1, M.stateRequired).refine(inCatalog(catalogs?.states), M.stateRequired),
    municipality: z.string().trim().min(1, M.municipalityRequired).min(2, M.municipalityLength).max(100, M.municipalityLength),
    employees_count: z
      .string()
      .trim()
      .min(1, M.employeesRequired)
      .refine((value) => value === '' || (/^\d+$/.test(value) && Number(value) >= 1 && Number(value) <= 1_000_000), M.employeesInvalid),
    business_activities: z
      .array(z.string())
      .min(1, M.activitiesRequired)
      .max(10, M.activitiesMax)
      .refine((codes) => codes.every(inCatalog(catalogs?.business_activities)), M.activitiesInvalid),
  })
}

export type OrganizationFormValues = z.input<ReturnType<typeof organizationSchema>>

export const ORGANIZATION_FIELDS = [
  'legal_name',
  'organization_type',
  'industry',
  'state',
  'municipality',
  'employees_count',
  'business_activities',
] as const satisfies readonly (keyof OrganizationFormValues)[]

export type OrganizationField = (typeof ORGANIZATION_FIELDS)[number]

export const EMPTY_ORGANIZATION_FORM: OrganizationFormValues = {
  legal_name: '',
  organization_type: '',
  industry: '',
  state: '',
  municipality: '',
  employees_count: '',
  business_activities: [],
}

export function toPayload(values: OrganizationFormValues): OrganizationPayload {
  return {
    legal_name: values.legal_name.trim(),
    organization_type: values.organization_type,
    industry: values.industry,
    state: values.state,
    municipality: values.municipality.trim(),
    employees_count: Number(values.employees_count.trim()),
    business_activities: values.business_activities,
  }
}
