import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'

export default function ProtectedRoute() {
  const { user, isLoading } = useAuth()
  const location = useLocation()

  if (isLoading) {
    return <div role="status" aria-label="Loading">Loading...</div>
  }

  if (!user) {
    const redirect = `${location.pathname}${location.search}`
    return <Navigate to={`/login?redirect=${encodeURIComponent(redirect)}`} replace />
  }

  return <Outlet />
}
