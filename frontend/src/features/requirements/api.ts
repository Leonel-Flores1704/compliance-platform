import { api } from '../../lib/api'
import type { CatalogOption } from '../organizations/api'
import type { RequirementStatus } from './messages'

export type UserOption = { id: number; name: string }

export type ComplianceRequirement = {
  id: number
  name: string
  description: string
  regulation: string
  deadline: string
  status: { code: RequirementStatus; label: string }
  responsible_user: UserOption | null
  required_documents: { id: number; name: string; category: CatalogOption }[]
  applies: boolean
  created_at: string | null
}

export type ComplianceRequirementPayload = {
  name: string
  description: string
  regulation: string
  deadline: string
  required_documents: { name: string; category: string }[]
  responsible_user_id: number
  status: RequirementStatus
}

export async function fetchUserOptions(): Promise<UserOption[]> {
  const res = await api.get<{ data: UserOption[] }>('/api/users/options')
  return res.data.data
}

export async function fetchDocumentCategories(): Promise<CatalogOption[]> {
  const res = await api.get<{ document_categories: CatalogOption[] }>('/api/catalogs/document-categories')
  return res.data.document_categories
}

export async function createRequirement(payload: ComplianceRequirementPayload): Promise<ComplianceRequirement> {
  // Sanctum SPA authentication needs the CSRF cookie before a state-changing request.
  await api.get('/sanctum/csrf-cookie')
  const res = await api.post<{ data: ComplianceRequirement; message: string }>('/api/compliance-requirements', payload)
  return res.data.data
}

export async function fetchRequirement(id: string): Promise<ComplianceRequirement> {
  const res = await api.get<{ data: ComplianceRequirement }>(`/api/compliance-requirements/${id}`)
  return res.data.data
}
