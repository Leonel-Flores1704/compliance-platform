import { useQuery } from '@tanstack/react-query'
import { api } from './api'

export type Role = 'administrator' | 'compliance_staff' | 'general_employee'

export type CurrentUser = {
  id: number
  name: string
  email: string
  role: Role
}

// Authenticated user from GET /api/user (Sanctum). Returns null when there is no session.
export function useCurrentUser() {
  return useQuery<CurrentUser | null>({
    queryKey: ['current-user'],
    retry: false,
    queryFn: async () => {
      try {
        const res = await api.get<CurrentUser>('/api/user')
        return res.data
      } catch {
        return null
      }
    },
  })
}

export const isAdministrator = (user: CurrentUser | null | undefined) => user?.role === 'administrator'

// ADR-005, function 3: create and edit compliance requirements.
export const canCreateRequirements = (role?: string) => role === 'administrator' || role === 'compliance_staff'
