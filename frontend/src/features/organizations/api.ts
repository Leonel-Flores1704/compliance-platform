import { api } from '../../lib/api'

export type CatalogOption = { code: string; name: string }

export type OrganizationCatalogs = {
  organization_types: CatalogOption[]
  industries: CatalogOption[]
  states: CatalogOption[]
  business_activities: CatalogOption[]
}

export type Organization = {
  id: number
  legal_name: string
  organization_type: CatalogOption
  industry: CatalogOption
  state: CatalogOption
  municipality: string
  employees_count: number
  business_activities: CatalogOption[]
  is_active: boolean
  created_at: string | null
}

export type OrganizationPayload = {
  legal_name: string
  organization_type: string
  industry: string
  state: string
  municipality: string
  employees_count: number
  business_activities: string[]
}

export async function fetchOrganizationCatalogs(): Promise<OrganizationCatalogs> {
  const res = await api.get<OrganizationCatalogs>('/api/catalogs/organization')
  return res.data
}

export async function createOrganization(payload: OrganizationPayload): Promise<Organization> {
  // Sanctum SPA authentication needs the CSRF cookie before a state-changing request.
  await api.get('/sanctum/csrf-cookie')
  const res = await api.post<{ data: Organization; message: string }>('/api/organizations', payload)
  return res.data.data
}

export async function fetchOrganization(id: string): Promise<Organization> {
  const res = await api.get<{ data: Organization }>(`/api/organizations/${id}`)
  return res.data.data
}
