import { useQuery } from '@tanstack/react-query'
import axios from 'axios'
import { Link } from 'react-router-dom'
import { api } from '../lib/api'
import { canCreateRequirements, isAdministrator, useCurrentUser } from '../lib/auth'

type Health = { status: string }

export default function HomePage() {
  const { data: user } = useCurrentUser()
  const { data, isLoading } = useQuery<Health>({
    queryKey: ['health'],
    retry: false,
    queryFn: async () => {
      try {
        // const res = await api.get<Health>('/api/health')
        const res = await api.get<Health>('http://localhost:8000/api/health')
        return res.data
      } catch (err) {
        if (axios.isAxiosError(err) && err.response?.data?.status) {
          return err.response.data as Health
        }
        return { status: 'unreachable' }
      }
    },
  })

  return (
    <main className="flex min-h-screen flex-col items-center justify-center gap-4">
      <h1 className="text-3xl font-bold">Compliance Platform</h1>
      <p className="text-lg">
        {isLoading ? 'Checking API...' : `API status: ${data?.status}`}
      </p>
      {isAdministrator(user) && (
        <Link
          to="/organizations/new"
          className="rounded-md bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800"
        >
          Register organization
        </Link>
      )}
      {canCreateRequirements(user?.role) && (
        <Link
          to="/requirements/new"
          className="rounded-md border border-blue-700 px-4 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-50"
        >
          New compliance requirement
        </Link>
      )}
    </main>
  )
}