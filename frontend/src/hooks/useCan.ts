import { useAuth } from '../auth/AuthContext'

export type Ability =
  | 'manage-users'
  | 'manage-organization'
  | 'manage-requirements'
  | 'change-compliance-status'
  | 'upload-documents'
  | 'view-documents'
  | 'view-dashboard'
  | 'use-ai-assistant'

const permissions: Record<Ability, readonly string[]> = {
  'manage-users': ['administrator'],
  'manage-organization': ['administrator'],
  'manage-requirements': ['administrator', 'compliance_staff'],
  'change-compliance-status': ['compliance_staff'],
  'upload-documents': ['administrator', 'compliance_staff'],
  'view-documents': ['administrator', 'compliance_staff', 'general_employee'],
  'view-dashboard': ['administrator', 'compliance_staff'],
  'use-ai-assistant': ['administrator', 'compliance_staff', 'general_employee'],
}

export function can(role: string | undefined, ability: Ability): boolean {
  return role !== undefined && permissions[ability].includes(role)
}

export function useCan(ability: Ability): boolean {
  const { user } = useAuth()

  return can(user?.role, ability)
}
