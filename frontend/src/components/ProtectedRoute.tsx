import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { Ability, useCan } from '../hooks/useCan'

type ProtectedRouteProps = {
  ability?: Ability
}

export default function ProtectedRoute({ ability }: ProtectedRouteProps) {
  const { user, isLoading } = useAuth()
  const location = useLocation()
  const isAllowed = useCan(ability ?? 'use-ai-assistant')

  if (isLoading) {
    return <div role="status" aria-label="Loading">Loading...</div>
  }

  if (!user) {
    const redirect = `${location.pathname}${location.search}`
    return <Navigate to={`/login?redirect=${encodeURIComponent(redirect)}`} replace />
  }

  if (ability && !isAllowed) {
    return <Navigate to="/403" replace />
  }

  return <Outlet />
}
