import { api } from '../../lib/api'
import type { ComplianceRequirement } from '../requirements/api'
import type { CriteriaGroup } from './model'

export type CriteriaPayload = { use_criteria: boolean; criteria: CriteriaGroup }

export type RequirementListItem = {
  id: number
  name: string
  regulation: string
  deadline: string
  status: { code: string; label: string }
  applies: boolean
}

export async function saveCriteria(requirementId: number, payload: CriteriaPayload) {
  await api.get('/sanctum/csrf-cookie')
  const res = await api.put<{ data: ComplianceRequirement; message: string }>(
    `/api/compliance-requirements/${requirementId}/criteria`,
    payload,
  )
  return res.data
}

export async function previewCriteria(payload: CriteriaPayload): Promise<boolean> {
  await api.get('/sanctum/csrf-cookie')
  const res = await api.post<{ applies: boolean }>('/api/compliance-requirements/criteria/preview', payload)
  return res.data.applies
}

// Only requirements that apply to the organization (applies = true).
export async function fetchRequirementList(): Promise<RequirementListItem[]> {
  const res = await api.get<{ data: RequirementListItem[] }>('/api/compliance-requirements')
  return res.data.data
}
